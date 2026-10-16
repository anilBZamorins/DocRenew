import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';

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
    tenantId: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  readonly submitting = signal(false);
  readonly errorMessage = signal<string | null>(null);

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { tenantId, email, password } = this.form.getRawValue();
    this.submitting.set(true);
    this.errorMessage.set(null);

    this.auth.login(tenantId!, email!, password!).subscribe({
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
