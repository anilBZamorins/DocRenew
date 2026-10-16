import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { environment } from '../../../environments/environment';

interface DemoAccount {
  role: string;
  email: string;
}

/** Seeded demo tenant users — password is the same for all of them in every dev/test environment. */
const DEMO_ACCOUNTS: DemoAccount[] = [
  { role: 'Admin', email: 'admin@docrenew.test' },
  { role: 'HR', email: 'hr@docrenew.test' },
  { role: 'Manager', email: 'manager@docrenew.test' },
  { role: 'Employee', email: 'emp1@docrenew.test' },
];
const DEMO_PASSWORD = 'Password123';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './login.component.html',
  styleUrl: './login.component.css',
})
export class LoginComponent {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  readonly submitting = signal(false);
  readonly errorMessage = signal<string | null>(null);

  /**
   * Testing convenience only — a role picker that fills email + password
   * for the seeded demo accounts, so switching roles while testing
   * doesn't mean retyping credentials each time. Gated on
   * !environment.production so it never ships in a production build
   * (environment.prod.ts has production: true and this block compiles
   * away entirely — see its empty render path in the template).
   */
  readonly isDev = !environment.production;
  readonly demoAccounts = DEMO_ACCOUNTS;

  selectDemoAccount(email: string): void {
    if (!email) return;
    this.form.patchValue({ email, password: DEMO_PASSWORD });
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { email, password } = this.form.getRawValue();
    this.submitting.set(true);
    this.errorMessage.set(null);

    this.auth.login(email!, password!).subscribe({
      next: () => {
        this.auth.fetchMe().subscribe({
          next: () => {
            this.submitting.set(false);
            this.router.navigate(['/dashboard']);
          },
          error: () => {
            this.submitting.set(false);
            this.router.navigate(['/dashboard']);
          },
        });
      },
      error: (err) => {
        this.submitting.set(false);
        const message = err?.error?.errors?.email?.[0] || err?.error?.message || 'Sign-in failed. Check your details and try again.';
        this.errorMessage.set(message);
      },
    });
  }
}
