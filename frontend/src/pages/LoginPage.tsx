import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import type { AuthSession } from '../types';

export default function LoginPage() {
  const navigate = useNavigate();
  const setSession = useAuthStore((s) => s.setSession);
  const [email, setEmail] = useState('cashier1@pos.test');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const deviceName = `${navigator.userAgent.slice(0, 40)}-${Date.now()}`;
      const { data } = await apiClient.post<AuthSession>('/login', {
        email,
        password,
        device_name: deviceName,
      });
      setSession(data);
      navigate('/checkout');
    } catch {
      setError('Invalid email or password.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center px-4">
      <form onSubmit={handleSubmit} className="w-full max-w-sm rounded-xl bg-slate-800 p-8 shadow-xl">
        <h1 className="mb-1 text-2xl font-semibold text-white">POS Checkout</h1>
        <p className="mb-6 text-sm text-slate-400">Sign in to start a shift on this terminal.</p>

        <label className="mb-1 block text-sm text-slate-300">Email</label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="mb-4 w-full rounded-md border border-slate-600 bg-slate-900 px-3 py-2 text-white outline-none focus:border-sky-500"
          required
        />

        <label className="mb-1 block text-sm text-slate-300">Password</label>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="mb-4 w-full rounded-md border border-slate-600 bg-slate-900 px-3 py-2 text-white outline-none focus:border-sky-500"
          required
        />

        {error && <p className="mb-4 text-sm text-red-400">{error}</p>}

        <button
          type="submit"
          disabled={loading}
          className="w-full rounded-md bg-sky-600 py-2 font-medium text-white hover:bg-sky-500 disabled:opacity-50"
        >
          {loading ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  );
}
