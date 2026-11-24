import { Injectable } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom, Observable, timeout } from 'rxjs';
import { AuthService } from './auth.service';
import { ApiResponse, ShedApiService } from './shed-api';
import { PeriodService } from './period';
import { WellnessService } from './wellness';
import { PeriodRecord } from '../models/period.model';
import { WellnessRecord } from '../models/wellness.model';
import { SyncJournalService, SyncJournalEntry, SyncRecordType } from './sync-journal';

export interface SyncRunResult {
  processed: number;
  synced: number;
  conflicts: number;
  pending: number;
  stoppedReason: string | null;
}

type Remote<T> = T & { clientRecordId?: string | null };

@Injectable({ providedIn: 'root' })
export class SyncEngineService {
  private running = false;
  private accountRevision = 0;

  constructor(
    private readonly auth: AuthService,
    private readonly api: ShedApiService,
    private readonly periods: PeriodService,
    private readonly wellness: WellnessService,
    private readonly journal: SyncJournalService
  ) {
    this.auth.currentUser$.subscribe(() => { this.accountRevision++; });
  }

  private async request<T>(source: Observable<T>): Promise<T> {
    return firstValueFrom(source.pipe(timeout(10000)));
  }

  // GET is scoped to the authenticated account by the PHP controllers.
  // Old local records and unknown server records are deliberately excluded.
  async runPending(): Promise<SyncRunResult> {
    const result: SyncRunResult = {
      processed: 0, synced: 0, conflicts: 0, pending: 0, stoppedReason: null
    };
    const userId = this.auth.currentUser?.id;
    const revision = this.accountRevision;
    const active = () => this.accountRevision === revision &&
      this.auth.currentUser?.id === userId && this.api.isAuthenticated();
    if (this.running || !userId || !active() || !navigator.onLine) return result;
    this.running = true;
    try {
      const entries = this.journal.getEntries();
      for (const type of ['period', 'wellness'] as const) {
        if (!active()) break;
        const scoped = entries.filter(entry => entry.recordType === type);
        // Always exercise GET, even for a newly signed-in account with no managed records.
        if (type === 'period') {
          const response = await this.request(this.api.getPeriods());
          await this.processType<PeriodRecord>(type, scoped, response, active, result,
            id => this.periods.getRecordById(id),
            (id, remote) => this.periods.applyServerRecord(id, {
              id, startDate: remote.startDate, endDate: remote.endDate,
              flow: remote.flow, notes: remote.notes
            }),
            (record, clientRecordId) => this.api.createPeriod({
              startDate: record.startDate, endDate: record.endDate,
              flow: record.flow, notes: record.notes, clientRecordId
            }),
            (id, record, clientRecordId) => this.api.updatePeriod(id, {
              startDate: record.startDate, endDate: record.endDate,
              flow: record.flow, notes: record.notes, clientRecordId
            }),
            id => this.api.deletePeriod(id),
            id => this.api.getPeriodById(id));
        } else {
          const response = await this.request(this.api.getWellnessRecords());
          await this.processType<WellnessRecord>(type, scoped, response, active, result,
            id => this.wellness.getRecordById(id),
            (id, remote) => this.wellness.applyServerRecord(id, {
              id, date: remote.date, mood: remote.mood,
              symptoms: [...remote.symptoms], notes: remote.notes
            }),
            (record, clientRecordId) => this.api.createWellness({
              date: record.date, mood: record.mood, symptoms: [...record.symptoms],
              notes: record.notes, clientRecordId
            }),
            (id, record, clientRecordId) => this.api.updateWellness(id, {
              date: record.date, mood: record.mood, symptoms: [...record.symptoms],
              notes: record.notes, clientRecordId
            }),
            id => this.api.deleteWellness(id),
            id => this.api.getWellnessById(id));
        }
      }
    } catch (error) {
      const status = error instanceof HttpErrorResponse ? error.status : null;
      result.stoppedReason = status === 401 || status === 403
        ? 'Authentication required.' : 'Server unavailable; local data retained.';
    } finally {
      this.running = false;
      if (active()) result.pending = this.journal.getPendingEntries().length;
    }
    return result;
  }

  private async processType<T extends { id: number }>(
    type: SyncRecordType,
    entries: SyncJournalEntry[],
    response: ApiResponse<Remote<T>[]>,
    active: () => boolean,
    result: SyncRunResult,
    readLocal: (id: number) => T | undefined,
    apply: (id: number, remote: T) => void,
    create: (record: T, uuid: string) => Observable<ApiResponse<T>>,
    update: (id: number, record: T, uuid: string) => Observable<ApiResponse<T>>,
    remove: (id: number) => Observable<{ success: boolean }>,
    readOne: (id: number) => Observable<ApiResponse<Remote<T>>>
  ): Promise<void> {
    if (!active()) return;
    if (!response.success || !Array.isArray(response.data)) throw new Error('Invalid GET response.');
    const same = (entry: SyncJournalEntry) => {
      if (!active()) return false;
      const latest = this.journal.getEntry(type, entry.localId);
      return latest?.clientRecordId === entry.clientRecordId &&
        latest.updatedAt === entry.updatedAt && latest.action === entry.action && latest.status === entry.status;
    };
    for (const entry of entries) {
      if (!active()) return;
      if (!same(entry) || entry.status === 'conflict') continue;
      const remote = response.data.find(item => item.clientRecordId === entry.clientRecordId);
      // Never use a numeric server ID unless it belongs to this exact UUID.
      if (remote && (!Number.isSafeInteger(remote.id) || remote.id <= 0 ||
          (entry.serverId !== null && remote.id !== entry.serverId))) continue;
      if (entry.status === 'synced') {
        // GET refreshes a known cached record, but never deletes or imports history.
        if (remote && readLocal(entry.localId)) apply(entry.localId, remote);
        continue;
      }
      result.processed++;
      try {
        if (entry.action === 'delete') {
          if (remote) {
            try {
              const deleted = await this.request(remove(remote.id));
              if (!deleted.success) throw new Error('DELETE failed.');
            } catch (error) {
              if (!(error instanceof HttpErrorResponse && error.status === 404)) throw error;
            }
          }
          if (same(entry)) {
            // Keep unmapped tombstones for later GET checks: a timed-out POST
            // may still be processed by the server after this GET.
            if (remote || entry.serverId !== null) {
              this.journal.completeDeletion(type, entry.localId);
              result.synced++;
            }
          }
          continue;
        }
        const local = readLocal(entry.localId);
        if (!local) continue;
        if (!remote && entry.serverId !== null) {
          // Do not recreate a previously mapped but missing record.
          result.conflicts++;
          continue;
        }
        // A prior POST may have succeeded despite a lost response. GET by UUID
        // prevents duplicate creation and permits a subsequent local edit.
        if (!remote) this.journal.markCreateAttempted(type, entry.localId);
        const saved = remote
          ? await this.request(update(remote.id, local, entry.clientRecordId))
          : await this.request(create(local, entry.clientRecordId));
        if (!active()) return;
        if (!saved.success || !Number.isSafeInteger(saved.data?.id) || saved.data.id <= 0 ||
            (remote && saved.data.id !== remote.id)) throw new Error('Invalid write response.');
        if (!same(entry)) continue; // A newer local edit/delete remains queued.
        const read = await this.request(readOne(saved.data.id));
        if (!same(entry)) continue;
        if (!read.success || read.data?.id !== saved.data.id ||
            read.data.clientRecordId !== entry.clientRecordId) throw new Error('Invalid GET confirmation.');
        apply(entry.localId, read.data);
        this.journal.markSynced(type, entry.localId, saved.data.id);
        result.synced++;
      } catch (error) {
        if (!active()) return;
        const status = error instanceof HttpErrorResponse ? error.status : null;
        if (status === 400 || status === 409 || status === 422 || status === 404) {
          // Keep pending and editable; do not block unrelated records.
          result.conflicts++;
          continue;
        }
        throw error;
      }
    }
  }
}
