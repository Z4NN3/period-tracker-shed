
import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

export type SyncRecordType = 'period' | 'wellness';

export type SyncAction =
  | 'create'
  | 'update'
  | 'delete';

export type SyncStatus =
  | 'pending'
  | 'synced'
  | 'conflict';

export interface SyncJournalEntry {
  recordType: SyncRecordType;
  localId: number;
  clientRecordId: string;
  serverId: number | null;
  action: SyncAction;
  status: SyncStatus;
  updatedAt: string;
  createAttempted?: boolean;
}

interface StoredJournal {
  version: 1;
  userId: number;
  entries: SyncJournalEntry[];
}

@Injectable({
  providedIn: 'root'
})
export class SyncJournalService {

  private readonly storagePrefix =
    'shed.syncJournal.user';

  private activeUserId: number | null = null;

  private readonly entriesSubject =
    new BehaviorSubject<SyncJournalEntry[]>([]);

  readonly entries$ =
    this.entriesSubject.asObservable();

  // ========================================
  // ACCOUNT ISOLATION
  // ========================================

  setActiveAccount(userId: number): void {

    if (
      !Number.isSafeInteger(userId) ||
      userId <= 0
    ) {
      throw new Error('Invalid account ID.');
    }

    const entries = this.loadEntries(userId);

    this.activeUserId = userId;
    this.entriesSubject.next(entries);
  }

  clearActiveAccount(): void {
    this.activeUserId = null;
    this.entriesSubject.next([]);
  }

  // ========================================
  // STORAGE
  // ========================================

  private storageKey(userId: number): string {
    return `${this.storagePrefix}.${userId}.rest-v2`;
  }

  private requireAccount(): number {

    if (this.activeUserId === null) {
      throw new Error(
        'A verified account is required.'
      );
    }

    return this.activeUserId;
  }

  private loadEntries(
    userId: number
  ): SyncJournalEntry[] {

    const saved = localStorage.getItem(
      this.storageKey(userId)
    );

    if (saved === null) {
      return [];
    }

    const parsed: unknown = JSON.parse(saved);

    if (
      !parsed ||
      typeof parsed !== 'object'
    ) {
      throw new Error('Invalid sync journal.');
    }

    const journal = parsed as StoredJournal;

    if (
      journal.version !== 1 ||
      journal.userId !== userId ||
      !Array.isArray(journal.entries) ||
      !journal.entries.every(
        entry => this.isValidEntry(entry)
      )
    ) {
      throw new Error(
        'Invalid account sync journal.'
      );
    }

    return journal.entries.map(
      entry => ({ ...entry })
    );
  }

  private isValidEntry(
    entry: SyncJournalEntry
  ): boolean {

    return (
      !!entry &&
      ['period', 'wellness'].includes(
        entry.recordType
      ) &&
      Number.isSafeInteger(entry.localId) &&
      entry.localId > 0 &&
      typeof entry.clientRecordId === 'string' &&
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
        entry.clientRecordId
      ) &&
      (
        entry.serverId === null ||
        (
          Number.isSafeInteger(entry.serverId) &&
          entry.serverId > 0
        )
      ) &&
      ['create', 'update', 'delete'].includes(
        entry.action
      ) &&
      ['pending', 'synced', 'conflict'].includes(
        entry.status
      ) &&
      typeof entry.updatedAt === 'string' &&
      (entry.createAttempted === undefined || typeof entry.createAttempted === 'boolean')
    );
  }

  private saveEntries(
    entries: SyncJournalEntry[]
  ): void {

    const userId = this.requireAccount();

    const journal: StoredJournal = {
      version: 1,
      userId,
      entries: entries.map(
        entry => ({ ...entry })
      )
    };

    // Persist first; publish only after success.
    localStorage.setItem(
      this.storageKey(userId),
      JSON.stringify(journal)
    );

    this.entriesSubject.next(
      journal.entries.map(
        entry => ({ ...entry })
      )
    );
  }

  // ========================================
  // READ ENTRIES
  // ========================================

  getEntries(): SyncJournalEntry[] {

    this.requireAccount();

    return this.entriesSubject.value.map(
      entry => ({ ...entry })
    );
  }

  getPendingEntries(): SyncJournalEntry[] {

    return this.getEntries().filter(
      entry => entry.status === 'pending'
    );
  }

  getEntry(
    recordType: SyncRecordType,
    localId: number
  ): SyncJournalEntry | undefined {

    return this.getEntries().find(
      entry =>
        entry.recordType === recordType &&
        entry.localId === localId
    );
  }

  // ========================================
  // REGISTER NEW RECORD
  // ========================================

  registerNewRecord(
    recordType: SyncRecordType,
    localId: number
  ): SyncJournalEntry {

    this.requireAccount();

    if (
      !['period', 'wellness'].includes(recordType) ||
      !Number.isSafeInteger(localId) ||
      localId <= 0
    ) {
      throw new Error(
        'Invalid record information.'
      );
    }

    const existing = this.getEntry(
      recordType,
      localId
    );

    if (existing) {
      return existing;
    }

    // getRandomValues works on local HTTP origins too.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    const uuid = `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;

    const entry: SyncJournalEntry = {
      recordType,
      localId,
      clientRecordId: uuid,
      serverId: null,
      action: 'create',
      status: 'pending',
      createAttempted: false,
      updatedAt: new Date().toISOString()
    };

    this.saveEntries([
      ...this.getEntries(),
      entry
    ]);

    return { ...entry };
  }

  // Persist evidence of an attempted POST BEFORE sending it. A lost
  // response must not make a later explicit deletion forget this UUID.
  markCreateAttempted(recordType: SyncRecordType, localId: number): void {
    const entries = this.getEntries();
    const entry = entries.find(item => item.recordType === recordType && item.localId === localId);
    if (!entry) throw new Error('Sync journal entry not found.');
    if (entry.createAttempted === true) return;
    entry.createAttempted = true;
    // This is transport metadata, not a new user edit; retain the revision.
    this.saveEntries(entries);
  }

  // ========================================
  // MARK SERVER CONFIRMATION
  // ========================================

  markSynced(
    recordType: SyncRecordType,
    localId: number,
    serverId: number
  ): void {

    if (
      !Number.isSafeInteger(serverId) ||
      serverId <= 0
    ) {
      throw new Error('Invalid server ID.');
    }

    const existing = this.getEntry(
      recordType,
      localId
    );

    if (!existing) {
      throw new Error(
        'Sync journal entry not found.'
      );
    }

    if (
      existing.serverId !== null &&
      existing.serverId !== serverId
    ) {
      throw new Error(
        'Conflicting server record mapping.'
      );
    }

    // Deletion acknowledgments must be handled
    // separately before enabling automatic sync.
    if (existing.action === 'delete') {
      throw new Error(
        'Use completeDeletion() for a deleted record.'
      );
    }

    this.updateEntry(recordType, localId, {
      serverId,
      status: 'synced'
    });
  }

  // ========================================
  // EXISTING RECORD EDITED
  // ========================================

  recordEdited(
    recordType: SyncRecordType,
    localId: number
  ): void {

    const existing = this.getEntry(
      recordType,
      localId
    );

    // Historical records without sync identity
    // remain local-only.
    if (!existing) {
      return;
    }

    if (existing.status === 'conflict') {
      throw new Error(
        'This record has an unresolved sync conflict.'
      );
    }

    if (existing.action === 'delete') {
      throw new Error(
        'This record is pending deletion.'
      );
    }

    if (existing.serverId === null) {

      // Editing an unsynced creation does not
      // create a second server operation.
      // Update its journal revision instead.
      this.updateEntry(recordType, localId, {
        action: 'create',
        status: 'pending'
      });

      return;
    }

    this.updateEntry(recordType, localId, {
      action: 'update',
      status: 'pending'
    });
  }

  // ========================================
  // EXISTING RECORD DELETED
  // ========================================

  recordDeleted(
    recordType: SyncRecordType,
    localId: number
  ): void {

    const existing = this.getEntry(
      recordType,
      localId
    );

    // No journal entry means historical local-only.
    if (!existing) {
      return;
    }

    if (existing.status === 'conflict') {
      throw new Error(
        'Resolve the sync conflict before deleting.'
      );
    }

    // A create that was never sent can safely be cancelled locally.
    if (existing.serverId === null && existing.createAttempted === false) {
      this.removeEntry(recordType, localId);
      return;
    }

    // Keep the server mapping as a tombstone
    // after local deletion.
    this.updateEntry(recordType, localId, {
      action: 'delete',
      status: 'pending'
    });
  }

  // ========================================
  // MARK PENDING CHANGE
  // ========================================

  markPending(
    recordType: SyncRecordType,
    localId: number,
    action: SyncAction
  ): void {

    const existing = this.getEntry(
      recordType,
      localId
    );

    if (!existing) {
      throw new Error(
        'Record must be registered before syncing.'
      );
    }

    if (existing.status === 'conflict') {
      throw new Error(
        'Resolve the sync conflict first.'
      );
    }

    if (existing.action === 'delete' &&
        action !== 'delete') {
      throw new Error(
        'Pending deletion must be resolved first.'
      );
    }

    if (action === 'create') {

      if (existing.serverId !== null) {
        throw new Error(
          'Record already has a server ID.'
        );
      }

      this.updateEntry(recordType, localId, {
        action: 'create',
        status: 'pending'
      });

      return;
    }

    if (action === 'update') {
      this.recordEdited(recordType, localId);
      return;
    }

    this.recordDeleted(recordType, localId);
  }

  // ========================================
  // MARK CONFLICT
  // ========================================

  markConflict(
    recordType: SyncRecordType,
    localId: number
  ): void {

    this.updateEntry(recordType, localId, {
      status: 'conflict'
    });
  }

  // ========================================
  // COMPLETE SERVER DELETION
  // ========================================

  completeDeletion(
    recordType: SyncRecordType,
    localId: number
  ): void {

    const existing = this.getEntry(
      recordType,
      localId
    );

    if (
      !existing ||
      existing.action !== 'delete'
    ) {
      throw new Error(
        'No pending server deletion to complete.'
      );
    }

    this.removeEntry(recordType, localId);
  }

  // ========================================
  // REMOVE JOURNAL ENTRY
  // ========================================

  private removeEntry(
    recordType: SyncRecordType,
    localId: number
  ): void {

    const remaining = this.getEntries().filter(
      entry =>
        !(
          entry.recordType === recordType &&
          entry.localId === localId
        )
    );

    this.saveEntries(remaining);
  }

  // ========================================
  // INTERNAL UPDATE
  // ========================================

  private updateEntry(
    recordType: SyncRecordType,
    localId: number,
    changes: Partial<SyncJournalEntry>
  ): void {

    const entries = this.getEntries();

    const index = entries.findIndex(
      entry =>
        entry.recordType === recordType &&
        entry.localId === localId
    );

    if (index === -1) {
      throw new Error(
        'Sync journal entry not found.'
      );
    }

    const updated: SyncJournalEntry = {
      ...entries[index],
      serverId:
        changes.serverId !== undefined
          ? changes.serverId
          : entries[index].serverId,
      action:
        changes.action ?? entries[index].action,
      status:
        changes.status ?? entries[index].status,
      updatedAt: new Date(Math.max(Date.now(), Date.parse(entries[index].updatedAt) + 1 || 0)).toISOString()
    };

    entries[index] = updated;

    this.saveEntries(entries);
  }
}
