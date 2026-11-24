
import { Injectable } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Capacitor, registerPlugin } from '@capacitor/core';
import { TimeoutError } from 'rxjs';

import {
  BehaviorSubject,
  firstValueFrom,
  timeout
} from 'rxjs';

import { PeriodService } from './period';
import { WellnessService } from './wellness';
import { SyncJournalService } from './sync-journal';

import {
  ShedApiService,
  ShedUser
} from './shed-api';

interface SavedNativeSession {
  token: string;
  user: ShedUser;
  expiresAt: number;
  server: string;
}
const secureSession = registerPlugin<{
  platformInfo(): Promise<{sdk: number}>;
  save(options: {value: string}): Promise<void>;
  load(): Promise<{value?: string}>;
  clear(): Promise<void>;
}>('ShedSession');

@Injectable({
  providedIn: 'root'
})
export class AuthService {

  private readonly sessionKey =
    'shed.auth.session.tab.v1';

  private readonly requestTimeout = 15000;

  private readonly userSubject =
    new BehaviorSubject<ShedUser | null>(null);

  readonly currentUser$ =
    this.userSubject.asObservable();

  private initialized = false;
  private nativeSession: SavedNativeSession | null = null;
  private get isAndroid(): boolean { return Capacitor.getPlatform() === 'android'; }

  get currentUser(): ShedUser | null {
    return this.userSubject.value;
  }

  constructor(
    private readonly api: ShedApiService,
    private readonly periods: PeriodService,
    private readonly wellness: WellnessService,
    private readonly syncJournal: SyncJournalService
  ) {}

  // ========================================
  // INITIALIZE / RESTORE SESSION
  // ========================================

  async initialize(): Promise<void> {
    if (this.initialized) {
      return;
    }

    this.initialized = true;

    let token: string | null = null;
    if (this.isAndroid) {
      try {
        const { sdk } = await secureSession.platformInfo();
        if (sdk < 35) document.documentElement.classList.add("shed-legacy-android");
        const { value } = await secureSession.load();
        if (!value) return;
        const saved = JSON.parse(value) as SavedNativeSession;
        if (!this.validToken(saved.token) || !this.validUser(saved.user) ||
            !Number.isFinite(saved.expiresAt) || saved.expiresAt <= Date.now() ||
            !this.api.restoreNativeServer(saved.server)) {
          await this.clearLocalSession();
          return;
        }
        this.nativeSession = saved;
        token = saved.token;
      } catch {
        try { await this.clearLocalSession(); } catch { /* fail closed */ }
        return;
      }
    } else {
      token = this.readSessionToken();
    }

    if (!token) {
      return;
    }

    this.clearVisibleAccount();

    try {
      this.api.setAccessToken(token);

      const response = await firstValueFrom(
        this.api.getCurrentUser().pipe(
          timeout(this.requestTimeout)
        )
      );

      if (
        !response.success ||
        !this.validUser(response.user) ||
        (this.nativeSession !== null && response.user.id !== this.nativeSession.user.id)
      ) {
        throw new Error('Invalid saved session.');
      }

      if (this.nativeSession) {
        this.nativeSession.user = response.user;
        await secureSession.save({value: JSON.stringify(this.nativeSession)});
      }
      this.activateAccount(response.user);

    } catch (error) {
      const offline = error instanceof TimeoutError ||
        (error instanceof HttpErrorResponse && error.status === 0);
      if (offline && this.nativeSession && this.nativeSession.expiresAt > Date.now()) {
        this.activateAccount(this.nativeSession.user);
      } else {
        await this.clearLocalSession();
      }
    }
  }

  // ========================================
  // LOGIN
  // ========================================

  async login(
    email: string,
    password: string
  ): Promise<ShedUser> {

    await this.clearLocalSession();

    try {
      const response = await firstValueFrom(
        this.api.login(email, password).pipe(
          timeout(this.requestTimeout)
        )
      );

      if (
        !response.success ||
        !this.validUser(response.user)
      ) {
        throw new Error(
          'Invalid login response from Shed API.'
        );
      }

      const token = response.accessToken;

      if (
        typeof token !== 'string' ||
        !this.validToken(token)
      ) {
        throw new Error(
          'The Shed API returned an invalid session token.'
        );
      }

      if (this.isAndroid) {
        const server = this.api.getNativeServer();
        if (!server || !Number.isFinite(response.expiresIn) || response.expiresIn <= 0) {
          throw new Error('Invalid session expiry or server.');
        }
        this.nativeSession = { token, user: response.user,
          expiresAt: Date.now() + response.expiresIn * 1000, server };
        await secureSession.save({value: JSON.stringify(this.nativeSession)});
      } else {
        this.saveSessionToken(token);
      }

      this.activateAccount(response.user);

      return response.user;

    } catch (error) {
      await this.clearLocalSession();
      throw error;
    }
  }

  // ========================================
  // REFRESH SIGNED-IN ACCOUNT DETAILS
  // ========================================

  async refreshCurrentUser(): Promise<ShedUser> {

    const originalUser = this.currentUser;

    if (!originalUser || !this.api.isAuthenticated()) {
      throw new Error('Please log in again.');
    }

    const response = await firstValueFrom(
      this.api.getCurrentUser().pipe(
        timeout(this.requestTimeout)
      )
    );

    if (
      !response.success ||
      !this.validUser(response.user) ||
      response.user.id !== originalUser.id
    ) {
      throw new Error(
        'Unable to verify updated account details.'
      );
    }

    // Do not publish a response if the account
    // was switched during the HTTP request.
    if (
      this.currentUser?.id !== originalUser.id
    ) {
      throw new Error(
        'Account changed while refreshing details.'
      );
    }

    // Preserve the current records, Sync Journal,
    // and account-specific profile.
    if (this.nativeSession) {
      this.nativeSession.user = response.user;
      await secureSession.save({value: JSON.stringify(this.nativeSession)});
    }
    this.userSubject.next(response.user);

    return response.user;
  }

  // ========================================
  // LOGOUT
  // ========================================

  async logout(): Promise<boolean> {

    let remoteLogout = null;

    try {
      if (this.api.isAuthenticated()) {
        remoteLogout = this.api.logout().pipe(
          timeout(this.requestTimeout)
        );
      }
    } catch {
      // Local logout must still proceed.
    }

    await this.clearLocalSession();

    if (!remoteLogout) {
      return false;
    }

    try {
      await firstValueFrom(remoteLogout);
      return true;
    } catch {
      return false;
    }
  }

  // ========================================
  // ACCOUNT ACTIVATION
  // ========================================

  private activateAccount(user: ShedUser): void {

    this.periods.setActiveAccount(user.id);

    this.wellness.setActiveAccount(user.id);

    this.syncJournal.setActiveAccount(user.id);

    this.userSubject.next(user);
  }

  // ========================================
  // CLEAR ACCOUNT CONTEXT
  // ========================================

  private clearVisibleAccount(): void {

    this.periods.clearActiveAccount();

    this.wellness.clearActiveAccount();

    this.syncJournal.clearActiveAccount();

    this.userSubject.next(null);
  }

  private async clearLocalSession(): Promise<void> {
    this.api.clearAccessToken();
    this.clearStoredToken();
    this.clearVisibleAccount();
    this.nativeSession = null;
    if (this.isAndroid) await secureSession.clear();
  }

  // ========================================
  // USER VALIDATION
  // ========================================

  private validUser(
    user: ShedUser | null | undefined
  ): user is ShedUser {
    return (
      !!user &&
      Number.isInteger(user.id) &&
      user.id > 0 &&
      typeof user.displayName === 'string' &&
      typeof user.email === 'string'
    );
  }

  private validToken(token: string): boolean {
    return /^[a-f0-9]{64}$/i.test(token);
  }

  // ========================================
  // READ SESSION TOKEN
  // ========================================

  private readSessionToken(): string | null {
    try {
      const token = sessionStorage.getItem(
        this.sessionKey
      );

      if (!token) {
        return null;
      }

      if (!this.validToken(token)) {
        this.clearStoredToken();
        return null;
      }

      return token;

    } catch {
      return null;
    }
  }

  // ========================================
  // SAVE SESSION TOKEN
  // ========================================

  private saveSessionToken(token: string): void {

    if (!this.validToken(token)) {
      throw new Error(
        'Invalid authentication token format.'
      );
    }

    try {
      sessionStorage.setItem(
        this.sessionKey,
        token
      );

      const savedToken = sessionStorage.getItem(
        this.sessionKey
      );

      if (savedToken !== token) {
        throw new Error(
          'Session token was not saved correctly.'
        );
      }

    } catch {
      throw new Error(
        'Unable to save your login session. ' +
        'Please check your browser storage settings.'
      );
    }
  }

  private clearStoredToken(): void {
    try {
      sessionStorage.removeItem(
        this.sessionKey
      );
    } catch {
      // In-memory credentials were still cleared.
    }
  }
}
