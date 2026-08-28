import React, { useState, useCallback } from 'react';
import axios from 'axios';
import Cookies from 'js-cookie';

interface LoginFormData {
  email: string;
  password: string;
}

interface AuthContextType {
  isAuthenticated: boolean;
  user: any | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  loading: boolean;
  error: string | null;
}

const AuthContext = React.createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [isAuthenticated, setIsAuthenticated] = useState<boolean>(
    !!Cookies.get('auth_token')
  );
  const [user, setUser] = useState<any | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const login = useCallback(async (email: string, password: string) => {
    setLoading(true);
    setError(null);

    try {
      const response = await axios.post('/api/v1/auth/login', {
        email,
        password
      });

      const { data } = response.data;
      const { access_token, refresh_token } = data.tokens;
      const userData = data.user;

      // Store tokens
      Cookies.set('auth_token', access_token, { expires: 1 });
      Cookies.set('refresh_token', refresh_token, { expires: 7 });

      // Store user data
      setUser(userData);
      setIsAuthenticated(true);

      // Set default auth header
      axios.defaults.headers.common['Authorization'] = `Bearer ${access_token}`;
    } catch (err: any) {
      const message = err.response?.data?.error || 'Login failed';
      setError(message);
      throw new Error(message);
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(() => {
    Cookies.remove('auth_token');
    Cookies.remove('refresh_token');
    setUser(null);
    setIsAuthenticated(false);
    delete axios.defaults.headers.common['Authorization'];
  }, []);

  // Initialize auth header if token exists
  React.useEffect(() => {
    const token = Cookies.get('auth_token');
    if (token) {
      axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    }
  }, []);

  return (
    <AuthContext.Provider value={{ isAuthenticated, user, login, logout, loading, error }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = React.useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
};
