import { FormEvent, useState } from 'react';
import { Coffee, LockKeyhole, Mail } from 'lucide-react';
import { useAuth } from '../../features/auth/AuthProvider';

export function LoginPage(){
  const {login}=useAuth(); const [email,setEmail]=useState(''); const [password,setPassword]=useState(''); const [busy,setBusy]=useState(false); const [error,setError]=useState('');
  async function submit(e:FormEvent){e.preventDefault();setError('');setBusy(true);try{await login(email,password);}catch{setError('We could not sign you in. Check your email and password and try again.');}finally{setBusy(false);}}
  return <main className="auth-page"><section className="auth-hero"><div className="brand-mark large"><Coffee/></div><span className="eyebrow">HOSPITALITY OS</span><h1>Run every shift with clarity.</h1><p>Orders, bar workflow, payments and cash control in one secure workspace.</p></section><form className="auth-card" onSubmit={submit}><div><span className="eyebrow">WELCOME BACK</span><h2>Sign in</h2><p>Use your staff account to continue.</p></div><label>Email<div className="input-with-icon"><Mail size={18}/><input type="email" autoComplete="email" value={email} onChange={e=>setEmail(e.target.value)} required autoFocus/></div></label><label>Password<div className="input-with-icon"><LockKeyhole size={18}/><input type="password" autoComplete="current-password" value={password} onChange={e=>setPassword(e.target.value)} required/></div></label>{error&&<div className="form-error" role="alert">{error}</div>}<button className="primary-action" disabled={busy}>{busy?'Signing in…':'Sign in securely'}</button></form></main>;
}
