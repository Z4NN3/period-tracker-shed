
import {
  Component,
  OnDestroy
} from '@angular/core';

import {
  Router,
  NavigationEnd
} from '@angular/router';

import {
  IonApp,
  IonRouterOutlet
} from '@ionic/angular';

import {
  combineLatest,
  debounceTime,
  fromEvent,
  interval,
  merge,
  filter,
  map,
  startWith,
  Subscription
} from 'rxjs';

import { AuthService } from './services/auth.service';
import { SyncJournalService } from './services/sync-journal';
import { SyncEngineService } from './services/sync-engine';

@Component({
  selector: 'app-root',
  templateUrl: 'app.component.html',
  standalone: true,
  imports: [IonApp, IonRouterOutlet]
})
export class AppComponent implements OnDestroy {

  private readonly navigationSubscription: Subscription;
  private readonly syncSubscription: Subscription;

  constructor(
    private readonly auth: AuthService,
    private readonly router: Router,
    journal: SyncJournalService,
    engine: SyncEngineService
  ) {
    // Only the new REST journal is observed. Old pending history is not uploaded.
    this.syncSubscription = merge(
      this.auth.currentUser$,
      journal.entries$,
      fromEvent(window, 'online'),
      fromEvent(window, 'focus'),
      fromEvent(document, 'visibilitychange'),
      interval(30000)
    ).pipe(debounceTime(200)).subscribe(() => {
      if (document.visibilityState !== 'hidden' && this.auth.currentUser) {
        void engine.runPending().catch(() => { /* Local data remains available. */ });
      }
    });
    const navigation$ = this.router.events.pipe(
      filter(
        event => event instanceof NavigationEnd
      ),
      map(() => this.router.url),
      startWith(this.router.url)
    );

    this.navigationSubscription = combineLatest([
      this.auth.currentUser$,
      navigation$
    ]).subscribe(([user, url]) => {
      if (!user && url.startsWith('/tabs')) {
        void this.router.navigateByUrl(
          '/welcome',
          { replaceUrl: true }
        );
      }
    });
  }

  ngOnDestroy(): void {
    this.navigationSubscription.unsubscribe();
    this.syncSubscription.unsubscribe();
  }
}
