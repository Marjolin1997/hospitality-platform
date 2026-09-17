import { FormEvent, useState } from 'react';
import {
  ArrowRight,
  Check,
  Coffee,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
  ShieldCheck,
  Sparkles,
} from 'lucide-react';
import { useAuth } from '../../features/auth/AuthProvider';

export function LoginPage() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    setError('');
    setBusy(true);

    try {
      await login(email.trim().toLowerCase(), password);
    } catch {
      setError('We could not sign you in. Check your email and password and try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="auth-page">
      <section className="auth-hero" aria-label="Hospitality OS">
        <div className="auth-brand">
          <div className="brand-mark auth-brand-mark"><Coffee size={24} strokeWidth={2.2} /></div>
          <div><strong>Hospitality OS</strong><span>Operations workspace</span></div>
        </div>

        <div className="auth-hero-content">
          <div className="auth-hero-badge"><Sparkles size={14} />Built for modern hospitality teams</div>
          <h1>Run every shift<span> with clarity.</span></h1>
          <p className="auth-hero-description">One secure workspace for service, orders, bar operations, payments and cash control.</p>

          <div className="auth-benefits">
            <div><span className="auth-benefit-icon"><Check size={15} strokeWidth={3} /></span><span><strong>Fast POS workflow</strong>Keep service moving during every shift.</span></div>
            <div><span className="auth-benefit-icon"><Check size={15} strokeWidth={3} /></span><span><strong>Live operations</strong>Coordinate orders and preparation in real time.</span></div>
            <div><span className="auth-benefit-icon"><Check size={15} strokeWidth={3} /></span><span><strong>Secure cash control</strong>Keep payments and shift reconciliation organized.</span></div>
          </div>
        </div>

        <div className="auth-hero-footer">
          <span><i className="auth-live-dot" />Secure workspace</span>
          <span>Built for hospitality teams</span>
        </div>
      </section>

      <section className="auth-form-side">
        <div className="auth-mobile-brand">
          <div className="brand-mark"><Coffee size={21} /></div>
          <div><strong>Hospitality OS</strong><span>Operations workspace</span></div>
        </div>

        <form className="auth-card" onSubmit={submit}>
          <div className="auth-card-header">
            <span className="auth-card-icon" aria-hidden="true"><ShieldCheck size={22} /></span>
            <span className="eyebrow">WELCOME BACK</span>
            <h2>Sign in to your workspace</h2>
            <p>Enter your staff credentials to continue securely.</p>
          </div>

          <div className="auth-fields">
            <label className="auth-field">
              <span>Email address</span>
              <div className="input-with-icon">
                <Mail size={18} aria-hidden="true" />
                <input type="email" inputMode="email" autoComplete="email" placeholder="you@company.com" value={email} onChange={(event) => setEmail(event.target.value)} disabled={busy} required autoFocus aria-label="Email address" />
              </div>
            </label>

            <label className="auth-field">
              <span>Password</span>
              <div className="input-with-icon">
                <LockKeyhole size={18} aria-hidden="true" />
                <input type={showPassword ? 'text' : 'password'} autoComplete="current-password" placeholder="Enter your password" value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} required aria-label="Password" />
                <button className="password-toggle" type="button" onClick={() => setShowPassword((visible) => !visible)} disabled={busy} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword}>
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </label>
          </div>

          {error && <div className="form-error visible" role="alert" aria-live="polite">{error}</div>}

          <button className="primary-action" type="submit" disabled={busy || !email.trim() || !password}>
            <span>{busy ? 'Signing you in…' : 'Sign in securely'}</span>
            {busy ? <span className="auth-spinner" aria-hidden="true" /> : <ArrowRight size={18} aria-hidden="true" />}
          </button>

          <div className="auth-security-note"><ShieldCheck size={15} aria-hidden="true" /><span>Your workspace is protected by secure authentication.</span></div>
        </form>

        <p className="auth-form-footer"><span>Hospitality OS</span><span aria-hidden="true">•</span><span>Secure operations workspace</span></p>
      </section>
    </main>
  );
}
