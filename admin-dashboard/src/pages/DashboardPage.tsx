import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import axios from 'axios';

const DashboardPage: React.FC = () => {
  const { user, logout } = useAuth();
  const [activeTab, setActiveTab] = useState('control');
  const [doorStatus, setDoorStatus] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');

  // Fetch door status
  useEffect(() => {
    fetchDoorStatus();
    const interval = setInterval(fetchDoorStatus, 5000);
    return () => clearInterval(interval);
  }, []);

  const fetchDoorStatus = async () => {
    try {
      const response = await axios.get('/api/v1/door/status');
      setDoorStatus(response.data.data);
    } catch (err) {
      console.error('Failed to fetch door status');
    }
  };

  const handleOpenDoor = async () => {
    setLoading(true);
    try {
      const response = await axios.post('/api/v1/door/open', {});
      setMessage('✅ تم فتح الباب بنجاح');
      setTimeout(() => setMessage(''), 3000);
      fetchDoorStatus();
    } catch (err: any) {
      setMessage('❌ خطأ: ' + (err.response?.data?.error || 'Failed to open door'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-100">
      {/* Header */}
      <header className="bg-white shadow">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">🚪 Smart Door Pro</h1>
            <p className="text-gray-600 text-sm">Admin Dashboard</p>
          </div>
          <div className="flex items-center space-x-4">
            <div className="text-right">
              <p className="text-gray-900 font-medium">{user?.email}</p>
              <p className="text-gray-600 text-sm capitalize">Role: {user?.role}</p>
            </div>
            <button
              onClick={logout}
              className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition"
            >
              تسجيل خروج
            </button>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Message Alert */}
        {message && (
          <div className="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            {message}
          </div>
        )}

        {/* Navigation Tabs */}
        <div className="flex space-x-4 mb-6 border-b border-gray-200">
          {[
            { id: 'control', label: '🎮 التحكم بالباب', icon: '🚪' },
            { id: 'qr', label: '📱 إنشاء باركود', icon: '📲' },
            { id: 'users', label: '👥 إدارة المستخدمين', icon: '👤' },
            { id: 'settings', label: '⚙️ إعدادات الجهاز', icon: '🔧' },
            { id: 'logs', label: '📊 السجلات', icon: '📈' }
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`px-6 py-3 font-medium text-sm ${
                activeTab === tab.id
                  ? 'text-blue-600 border-b-2 border-blue-600'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              {tab.icon} {tab.label}
            </button>
          ))}
        </div>

        {/* Tab Content */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Main Content */}
          <div className="lg:col-span-2">
            {activeTab === 'control' && <DoorControlTab doorStatus={doorStatus} onOpen={handleOpenDoor} loading={loading} />}
            {activeTab === 'qr' && <QRCodeTab />}
            {activeTab === 'users' && <UsersTab />}
            {activeTab === 'settings' && <SettingsTab />}
            {activeTab === 'logs' && <LogsTab />}
          </div>

          {/* Sidebar */}
          <div>
            <DoorStatusWidget doorStatus={doorStatus} />
          </div>
        </div>
      </main>
    </div>
  );
};

// Door Control Tab Component
const DoorControlTab: React.FC<{ doorStatus: any; onOpen: () => void; loading: boolean }> = ({
  doorStatus,
  onOpen,
  loading
}) => (
  <div className="bg-white rounded-lg shadow-md p-6">
    <h2 className="text-2xl font-bold mb-6">🚪 التحكم بالباب</h2>

    {/* Door Status Display */}
    <div className="mb-6 p-6 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border-2 border-blue-200">
      <div className="flex items-center justify-between mb-4">
        <span className="text-lg font-medium text-gray-700">حالة الباب:</span>
        <span
          className={`px-4 py-2 rounded-full font-bold ${
            doorStatus?.online
              ? 'bg-green-100 text-green-800'
              : 'bg-red-100 text-red-800'
          }`}
        >
          {doorStatus?.online ? '🟢 متصل' : '🔴 غير متصل'}
        </span>
      </div>
      {doorStatus?.last_seen_at && (
        <p className="text-sm text-gray-600">
          آخر تحديث: {new Date(doorStatus.last_seen_at).toLocaleString('ar-EG')}
        </p>
      )}
    </div>

    {/* Open Door Button */}
    <div className="flex gap-4">
      <button
        onClick={onOpen}
        disabled={loading || !doorStatus?.online}
        className="flex-1 bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-4 px-6 rounded-lg text-xl transition duration-200 shadow-lg"
      >
        {loading ? '⏳ جاري...' : '🔓 فتح الباب'}
      </button>
      <button
        disabled={loading}
        className="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-4 px-6 rounded-lg text-xl transition duration-200 shadow-lg"
      >
        {loading ? '⏳ جاري...' : '🔒 قفل الباب'}
      </button>
    </div>

    {/* Door Settings */}
    <div className="mt-8 p-4 bg-gray-50 rounded-lg">
      <h3 className="font-bold mb-4">⚙️ إعدادات مدة الفتح</h3>
      <div className="space-y-3">
        <div>
          <label className="block text-sm font-medium mb-1">مدة الفتح (ملي ثانية)</label>
          <input
            type="number"
            defaultValue="3000"
            min="500"
            max="15000"
            className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <button className="w-full bg-blue-600 text-white font-medium py-2 rounded-lg hover:bg-blue-700">
          💾 حفظ الإعدادات
        </button>
      </div>
    </div>
  </div>
);

// QR Code Tab Component
const QRCodeTab: React.FC = () => {
  const [qrValue, setQrValue] = useState('');
  const [maxUses, setMaxUses] = useState(1);
  const [expiresIn, setExpiresIn] = useState(24);
  const [loading, setLoading] = useState(false);

  const handleGenerateQR = async () => {
    setLoading(true);
    try {
      const response = await axios.post('/api/v1/passes', {
        max_uses: maxUses,
        expires_in_hours: expiresIn
      });
      const { qr_content, token } = response.data.data;
      setQrValue(qr_content);
    } catch (err) {
      console.error('Failed to generate QR');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-6">
      <h2 className="text-2xl font-bold mb-6">📱 إنشاء باركود</h2>

      <div className="grid md:grid-cols-2 gap-6">
        {/* Configuration */}
        <div>
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-2">عدد الاستخدامات</label>
              <input
                type="number"
                value={maxUses}
                onChange={(e) => setMaxUses(parseInt(e.target.value))}
                min="1"
                max="100"
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-2">انتهاء الصلاحية (ساعات)</label>
              <input
                type="number"
                value={expiresIn}
                onChange={(e) => setExpiresIn(parseInt(e.target.value))}
                min="1"
                max="720"
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <button
              onClick={handleGenerateQR}
              disabled={loading}
              className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-bold py-2 rounded-lg transition"
            >
              {loading ? '⏳ جاري...' : '📲 إنشاء باركود'}
            </button>
          </div>
        </div>

        {/* QR Display */}
        {qrValue && (
          <div className="flex flex-col items-center justify-center p-6 bg-gray-50 rounded-lg">
            <div
              dangerouslySetInnerHTML={{
                __html: `<img src="${qrValue}" alt="QR Code" style="width: 250px; height: 250px;" />`
              }}
            />
            <button className="mt-4 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
              🖨️ طباعة
            </button>
          </div>
        )}
      </div>
    </div>
  );
};

// Users Tab Component
const UsersTab: React.FC = () => (
  <div className="bg-white rounded-lg shadow-md p-6">
    <h2 className="text-2xl font-bold mb-6">👥 إدارة المستخدمين</h2>
    <div className="space-y-4">
      <button className="w-full bg-blue-600 text-white font-bold py-2 rounded-lg hover:bg-blue-700">
        ➕ إضافة مستخدم جديد
      </button>
      {/* Users list will be populated here */}
      <div className="border rounded-lg p-4 text-gray-600 text-center">
        جاري تحميل قائمة المستخدمين...
      </div>
    </div>
  </div>
);

// Settings Tab Component
const SettingsTab: React.FC = () => (
  <div className="bg-white rounded-lg shadow-md p-6">
    <h2 className="text-2xl font-bold mb-6">⚙️ إعدادات الجهاز</h2>
    <div className="space-y-6">
      <div>
        <label className="block text-sm font-medium mb-2">اسم الباب</label>
        <input type="text" defaultValue="الباب الرئيسي" className="w-full px-3 py-2 border rounded-lg" />
      </div>
      <div>
        <label className="block text-sm font-medium mb-2">مدة الفتح (ملي ثانية)</label>
        <input type="number" defaultValue="3000" className="w-full px-3 py-2 border rounded-lg" />
      </div>
      <div>
        <label className="block text-sm font-medium mb-2">مستوى التفعيل</label>
        <select className="w-full px-3 py-2 border rounded-lg">
          <option>مرتفع (HIGH)</option>
          <option>منخفض (LOW)</option>
        </select>
      </div>
      <button className="w-full bg-green-600 text-white font-bold py-2 rounded-lg hover:bg-green-700">
        💾 حفظ الإعدادات
      </button>
    </div>
  </div>
);

// Logs Tab Component
const LogsTab: React.FC = () => (
  <div className="bg-white rounded-lg shadow-md p-6">
    <h2 className="text-2xl font-bold mb-6">📊 السجلات</h2>
    <div className="space-y-4">
      {/* Sample logs */}
      <div className="border-l-4 border-green-500 pl-4 py-2">
        <p className="font-medium">✅ تم فتح الباب بنجاح</p>
        <p className="text-sm text-gray-600">2024-08-28 10:30:00 - admin@smartdoor.com</p>
      </div>
      <div className="border-l-4 border-blue-500 pl-4 py-2">
        <p className="font-medium">📱 تم إنشاء باركود جديد</p>
        <p className="text-sm text-gray-600">2024-08-28 10:25:00 - admin@smartdoor.com</p>
      </div>
    </div>
  </div>
);

// Door Status Widget Component
const DoorStatusWidget: React.FC<{ doorStatus: any }> = ({ doorStatus }) => (
  <div className="bg-white rounded-lg shadow-md p-6">
    <h3 className="text-lg font-bold mb-4">📊 معلومات الجهاز</h3>
    <div className="space-y-4">
      <div className="flex justify-between">
        <span className="text-gray-600">الحالة:</span>
        <span className={doorStatus?.online ? 'text-green-600 font-bold' : 'text-red-600 font-bold'}>
          {doorStatus?.online ? '🟢 متصل' : '🔴 غير متصل'}
        </span>
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-gray-600">إصدار البرنامج الثابت:</span>
        <span className="font-medium">{doorStatus?.firmware_version || 'N/A'}</span>
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-gray-600">آخر تحديث:</span>
        <span className="font-medium">
          {doorStatus?.last_seen_at ? new Date(doorStatus.last_seen_at).toLocaleTimeString('ar-EG') : 'N/A'}
        </span>
      </div>
      <div className="pt-4 border-t">
        <button className="w-full px-3 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 text-sm font-medium">
          🔄 تحديث الآن
        </button>
      </div>
    </div>
  </div>
);

export default DashboardPage;
