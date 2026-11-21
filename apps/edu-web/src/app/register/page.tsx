'use client';

import Link from 'next/link';
import { useEffect, useState, type FormEvent } from 'react';
import { AuthError, browserRequest } from '../../lib/browser-auth';
import '../onboarding.css';

type Notice = { notice_version: string; notice_text: string };
type Status = { id: string; state: string; support_contact: string; tracking_token?: string };

const fields = [
  { name: 'organization_name', label: 'School or organisation name', max: 160 },
  { name: 'category', label: 'Organisation category', max: 40 },
  { name: 'contact_name', label: 'Applicant name', max: 100 },
  { name: 'contact', label: 'Email or international phone number', max: 160 },
];

export default function RegistrationPage() {
  const [notice, setNotice] = useState<Notice>();
  const [noticeLoading, setNoticeLoading] = useState(true);
  const [noticeError, setNoticeError] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [result, setResult] = useState<Status>();
  const [view, setView] = useState<'apply' | 'track'>('apply');

  useEffect(() => {
    const request = new AbortController();
    browserRequest<Notice>('registration/notice', undefined, { signal: request.signal })
      .then(setNotice)
      .catch(() => { if (!request.signal.aborted) setNoticeError('School registration is currently unavailable.'); })
      .finally(() => { if (!request.signal.aborted) setNoticeLoading(false); });
    return () => request.abort();
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || (view === 'apply' && !notice)) return;
    const form = event.currentTarget;
    const values = new FormData(form);
    const body = view === 'apply'
      ? { ...Object.fromEntries(fields.map(({ name }) => [name, String(values.get(name) ?? '').trim()])), notice_version: notice!.notice_version, notice_acknowledged: values.has('notice_acknowledged') }
      : { tracking_token: String(values.get('tracking_token') ?? '').trim() };
    setBusy(true);
    setError('');
    setFieldErrors({});
    setResult(undefined);
    try {
      setResult(await browserRequest<Status>(view === 'apply' ? 'registration/submit' : 'registration/status', body));
      form.reset();
    } catch (failure) {
      if (failure instanceof AuthError) setFieldErrors(failure.fields);
      setError(failure instanceof AuthError && failure.status === 404 && view === 'track'
        ? 'We could not find an application for this tracking code.'
        : failure instanceof AuthError ? failure.message : 'Unable to connect. Try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="onboard">
      <nav className="onboard-nav" aria-label="Education">
        <Link href="/">NUVIKOZ Edu</Link><Link href="/login">School sign-in</Link>
      </nav>
      <div className="onboard-grid">
        <header><h1>{view === 'apply' ? 'Register your school' : 'Track your application'}</h1>
          <p>{view === 'apply' ? 'Submit your school and applicant details for platform review.' : 'Use the private tracking code issued when you applied.'}</p>
          <button type="button" className="onboard-secondary" disabled={busy} onClick={() => {
            setView(view === 'apply' ? 'track' : 'apply'); setResult(undefined); setError(''); setFieldErrors({});
          }}>{view === 'apply' ? 'Track an application' : 'Start an application'}</button>
        </header>
        <section className="onboard-card" aria-label="School application">
          {result ? <div role="status"><h2>Application {result.state}</h2><p>Reference <strong>{result.id}</strong></p>
            {result.state === 'approved' && <p>School access is activated separately from the application decision.</p>}
            {result.tracking_token && <><label htmlFor="private-code">Private tracking code</label><input id="private-code" readOnly value={result.tracking_token} />
              <p>Save this code privately. You will need it to check your application status.</p></>}
            {result.support_contact && <p>Support: {result.support_contact}</p>}
          </div> : view === 'apply' && !notice ? <p role={noticeError ? 'alert' : 'status'}>{noticeLoading ? 'Loading registration notice…' : noticeError}</p>
            : <form key={view} onSubmit={submit} aria-busy={busy}>
              <fieldset disabled={busy}>
                {view === 'apply' ? <>{fields.map(({ name, label, max }) => <div key={name}>
                  <label htmlFor={name}>{label}</label>
                  <input id={name} name={name} maxLength={max} required aria-invalid={Boolean(fieldErrors[name])}
                    aria-describedby={fieldErrors[name] ? `error-${name}` : undefined} />
                  {fieldErrors[name] && <p id={`error-${name}`} className="onboard-error">{fieldErrors[name].join(' ')}</p>}
                </div>)}
                <details><summary>Privacy notice ({notice?.notice_version})</summary><p className="onboard-notice">{notice?.notice_text}</p></details>
                <label className="onboard-check"><input type="checkbox" name="notice_acknowledged" required /> I have read the privacy notice.</label>
                </> : <><label htmlFor="tracking">Private tracking code</label><input id="tracking" name="tracking_token" minLength={43} maxLength={43} required autoComplete="off" spellCheck={false} /></>}
                {error && <p role="alert" className="onboard-error">{error}</p>}
                <button type="submit">{busy ? 'Please wait…' : view === 'apply' ? 'Submit application' : 'Check status'}</button>
              </fieldset>
            </form>}
        </section>
      </div>
    </main>
  );
}
