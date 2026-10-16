import { Component, OnInit } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { AuthService } from './core/auth.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css',
})
export class AppComponent implements OnInit {
  title = 'docrenew-frontend';

  constructor(private readonly auth: AuthService) {}

  ngOnInit(): void {
    // A page reload loses the in-memory currentUser signal even though
    // the access token is still in localStorage — re-hydrate it so
    // role-scoped UI (the Admin/HR-only toolbar) renders correctly
    // without forcing a fresh login every time.
    if (this.auth.isAuthenticated) {
      this.auth.fetchMe().subscribe({ error: () => {} });
    }
  }
}
