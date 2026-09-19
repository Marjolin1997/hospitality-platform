import { useMutation, useQuery } from '@tanstack/react-query';
import {
  ArrowRight,
  Check,
  Coffee,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
  ShieldCheck,
  UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';
import { api, initializeCsrf } from '../../lib/api';

type InvitationPreview = {
  email: string;
  business_name: string;
  role_name: string;
  status: 'pending' | 'accepted' | 'revoked' | 'expired' | 'unavailable';
  expires_at: string;
  existing_user: boolean;
};

function apiMessage(error: unknown): string {
  const response = (error as {
    response?: { data?: { errors?: Record<string, string[]>; message?: string } };
  })?.response;
  const errors = response?.data?.errors;

  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (typeof first === 'string') return first;
  }

  return response?.data?.message ?? 'The invitation could not be accepted.';
}

function closedStatusCopy(status: InvitationPreview['status']) {
  if (status === 'accepted') {
    return {
      title: 'Invitation already used',
      message: 'This secure invitation has already been accepted and cannot be used again.',
    };
  }

  if (status === 'revoked') {
    return {
      title: 'Invitation revoked',
      message: 'A manager revoked this invitation. Ask the business for a new link if you still need access.',
    };
  }

  if (status === 'expired') {
    return {
      title: 'Invitation expired',
      message: 'This invitation passed its expiry time. Ask a manager to issue a fresh invitation.',
    };
  }

  return {
    title: 'Invitation unavailable',
    message: 'The business is not currently accepting this invitation.',
  };
}

export function InvitationAcceptPage() {
  const { token = '' } = useParams();
  const navigate = useNavigate();
  const { refreshUser } = useAuth();

  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);

  const invitation = useQuery({
    queryKey: ['invitation-preview', token],
    enabled: token.length > 0,
    retry: false,
    queryFn: () => api
      .get<{ data: InvitationPreview }>(`/invitations/${encodeURIComponent(token)}`)
      .then(response => response.data.data),
  });

  const accept = useMutation({
    mutationFn: async () => {
      await initializeCsrf();
      await api.post(`/invitations/${encodeURIComponent(token)}/accept`, {
        name: name.trim() || null,
        password,
        password_confirmation: passwordConfirmation,
      });
    },
    onSuccess: async () => {
      await refreshUser();
      navigate('/dashboard', { replace: true });
    },
  });

  const data = invitation.data;
  const passwordReady = password.length >= 12 && password === passwordConfirmation;
  const formReady = Boolean(
    data
    && data.status === 'pending'
    && passwordReady
    && (data.existing_user || name.trim().length > 0),
  );

  return (
    <main className="auth-page invitation-auth-page">
      <section className="auth-hero" aria-label="Hospitality OS staff onboarding">
        <div className="auth-brand">
          <div className="brand-mark auth-brand-mark"><Coffee size={24} strokeWidth={2.2} /></div>
          <div><strong>Hospitality OS</strong><span>Secure staff onboarding</span></div>
        </div>

        <div className="auth-hero-content">
          <div className="auth-hero-badge"><ShieldCheck size={14} />Protected invitation flow</div>
          <h1>Join your team<span> securely.</span></h1>
          <p className="auth-hero-description">
            Your invitation is tenant-scoped, time-limited and can be accepted only once.
          </p>

          <div className="auth-benefits">
            <div>
              <span className="auth-benefit-icon"><Check size={15} strokeWidth={3} /></span>
              <span><strong>Least-privilege access</strong>Your role is fixed by the business invitation.</span>
            </div>
            <div>
              <span className="auth-benefit-icon"><Check size={15} strokeWidth={3} /></span>
              <span><strong>One-time acceptance</strong>The invitation cannot be replayed after success.</span>
            </div>
            <div>
              <span className="auth-benefit-icon"><Check size={15} strokeWidth={3} /></span>
              <span><strong>Account protection</strong>Existing users must prove their current account password.</span>
            </div>
          </div>
        </div>

        <div className="auth-hero-footer">
          <span><i className="auth-live-dot" />Secure onboarding</span>
          <span>Hospitality OS</span>
        </div>
      </section>

      <section className="auth-form-side">
        <div className="auth-mobile-brand">
          <div className="brand-mark"><Coffee size={21} /></div>
          <div><strong>Hospitality OS</strong><span>Secure staff onboarding</span></div>
        </div>

        <div className="auth-card invitation-auth-card">
          {invitation.isLoading ? (
            <div className="invitation-page-state">
              <span className="auth-spinner" aria-hidden="true" />
              <strong>Verifying invitation…</strong>
              <p>Checking the secure token and assigned access.</p>
            </div>
          ) : invitation.isError || !data ? (
            <div className="invitation-page-state error">
              <span className="auth-card-icon"><ShieldCheck size={22} /></span>
              <span className="eyebrow">INVALID INVITATION</span>
              <h2>We could not verify this link</h2>
              <p>The invitation may be invalid or no longer exist. Ask the business for a new invitation.</p>
              <button type="button" className="secondary-button" onClick={() => navigate('/', { replace: true })}>
                Go to sign in
              </button>
            </div>
          ) : data.status !== 'pending' ? (
            <div className="invitation-page-state">
              <span className="auth-card-icon"><ShieldCheck size={22} /></span>
              <span className="eyebrow">INVITATION STATUS</span>
              <h2>{closedStatusCopy(data.status).title}</h2>
              <p>{closedStatusCopy(data.status).message}</p>
              <button type="button" className="secondary-button" onClick={() => navigate('/', { replace: true })}>
                Go to sign in
              </button>
            </div>
          ) : (
            <form
              onSubmit={event => {
                event.preventDefault();
                if (formReady && !accept.isPending) accept.mutate();
              }}
            >
              <div className="auth-card-header invitation-card-header">
                <span className="auth-card-icon" aria-hidden="true"><UserPlus size={22} /></span>
                <span className="eyebrow">STAFF INVITATION</span>
                <h2>Join {data.business_name}</h2>
                <p>
                  You were invited as <strong>{data.role_name}</strong>. This link expires on{' '}
                  {new Date(data.expires_at).toLocaleString()}.
                </p>
              </div>

              <div className="invitation-assignment">
                <div>
                  <span>Invited account</span>
                  <strong>{data.email}</strong>
                </div>
                <div>
                  <span>Assigned role</span>
                  <strong>{data.role_name}</strong>
                </div>
              </div>

              <div className="auth-fields">
                {!data.existing_user && (
                  <label className="auth-field">
                    <span>Your full name</span>
                    <div className="input-with-icon">
                      <UserPlus size={18} aria-hidden="true" />
                      <input
                        value={name}
                        maxLength={120}
                        autoComplete="name"
                        placeholder="Your name"
                        onChange={event => setName(event.target.value)}
                        disabled={accept.isPending}
                        required
                        autoFocus
                      />
                    </div>
                  </label>
                )}

                <label className="auth-field">
                  <span>{data.existing_user ? 'Current account password' : 'Create password'}</span>
                  <div className="input-with-icon">
                    <LockKeyhole size={18} aria-hidden="true" />
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={password}
                      autoComplete={data.existing_user ? 'current-password' : 'new-password'}
                      placeholder={data.existing_user ? 'Enter your current password' : 'Create a strong password'}
                      onChange={event => setPassword(event.target.value)}
                      disabled={accept.isPending}
                      required
                      autoFocus={data.existing_user}
                    />
                    <button
                      className="password-toggle"
                      type="button"
                      onClick={() => setShowPassword(visible => !visible)}
                      disabled={accept.isPending}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      aria-pressed={showPassword}
                    >
                      {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                  </div>
                  {!data.existing_user && (
                    <small>Minimum 12 characters with upper/lower case, number and symbol.</small>
                  )}
                </label>

                <label className="auth-field">
                  <span>Confirm password</span>
                  <div className="input-with-icon">
                    <LockKeyhole size={18} aria-hidden="true" />
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={passwordConfirmation}
                      autoComplete={data.existing_user ? 'current-password' : 'new-password'}
                      placeholder="Repeat password"
                      onChange={event => setPasswordConfirmation(event.target.value)}
                      disabled={accept.isPending}
                      required
                    />
                  </div>
                </label>
              </div>

              {data.existing_user && (
                <div className="auth-security-note">
                  <Mail size={15} aria-hidden="true" />
                  <span>
                    This email already has a Hospitality OS account. Your current password is required before the new business membership can be attached.
                  </span>
                </div>
              )}

              {passwordConfirmation && password !== passwordConfirmation && (
                <div className="form-error visible" role="alert">Passwords do not match.</div>
              )}

              {accept.isError && (
                <div className="form-error visible" role="alert" aria-live="polite">
                  {apiMessage(accept.error)}
                </div>
              )}

              <button className="primary-action" type="submit" disabled={accept.isPending || !formReady}>
                <span>{accept.isPending ? 'Joining workspace…' : data.existing_user ? 'Verify & join workspace' : 'Create account & join'}</span>
                {accept.isPending
                  ? <span className="auth-spinner" aria-hidden="true" />
                  : <ArrowRight size={18} aria-hidden="true" />}
              </button>

              <div className="auth-security-note">
                <ShieldCheck size={15} aria-hidden="true" />
                <span>The invitation token becomes permanently unusable immediately after successful acceptance.</span>
              </div>
            </form>
          )}
        </div>

        <p className="auth-form-footer">
          <span>Hospitality OS</span>
          <span aria-hidden="true">•</span>
          <span>Secure team onboarding</span>
        </p>
      </section>
    </main>
  );
}
