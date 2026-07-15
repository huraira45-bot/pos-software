import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import { Button, Card, Input } from '../components/ui';
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
    <div className="flex min-h-full items-center justify-center bg-canvas px-4">
      <Card className="w-full max-w-sm p-8">
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <h1 className="mb-1 text-xl font-semibold text-primary-700">CHANGAN MULTAN MOTORS</h1>
            <p className="text-sm text-ink-muted">Sign in to start a shift on this terminal.</p>
          </div>

          <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          <Input label="Password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />

          {error && <p className="text-sm text-danger">{error}</p>}

          <Button type="submit" variant="primary" loading={loading} className="w-full py-2">
            {loading ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>
      </Card>
    </div>
  );
}
