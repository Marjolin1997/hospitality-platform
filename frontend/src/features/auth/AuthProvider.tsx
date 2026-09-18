import { createContext, type ReactNode, useContext, useEffect, useMemo, useState } from 'react';
import { api, clearWorkspaceContext, getActiveBusinessId, getActiveLocationId, initializeCsrf, setActiveBusinessId, setActiveLocationId } from '../../lib/api';

export type Location = { id: string; name: string };
export type BusinessRole = { id: string; name: string; slug: string };
export type Business = {
  id: string;
  name: string;
  currency: string;
  timezone: string;
  role_id: string|null;
  role: BusinessRole|null;
  permissions: string[];
  locations: Location[];
};
export type AuthUser = { id: number; name: string; email: string; businesses: Business[] };

type AuthContextValue = {
  user: AuthUser|null;
  loading: boolean;
  activeBusiness: Business|null;
  activeLocation: Location|null;
  can(permission:string):boolean;
  canAny(permissions:string[]):boolean;
  login(email:string,password:string):Promise<void>;
  logout():Promise<void>;
  refreshUser():Promise<void>;
  selectBusiness(id:string):void;
  selectLocation(id:string):void;
};
const AuthContext = createContext<AuthContextValue|null>(null);

export function AuthProvider({children}:{children:ReactNode}) {
  const [user,setUser] = useState<AuthUser|null>(null); const [loading,setLoading] = useState(true);
  const [businessId,setBusinessId] = useState(getActiveBusinessId()); const [locationId,setLocationId] = useState(getActiveLocationId());

  useEffect(() => { api.get<{data:AuthUser}>('/auth/me').then(r => setUser(r.data.data)).catch(() => setUser(null)).finally(() => setLoading(false)); }, []);
  useEffect(() => {
    if (!user) return;
    const validBusiness = user.businesses.find(b => b.id === businessId) ?? user.businesses[0] ?? null;
    if (validBusiness && validBusiness.id !== businessId) { setBusinessId(validBusiness.id); setActiveBusinessId(validBusiness.id); }
    const validLocation = validBusiness?.locations.find(l => l.id === locationId) ?? validBusiness?.locations[0] ?? null;
    if (validLocation && validLocation.id !== locationId) { setLocationId(validLocation.id); setActiveLocationId(validLocation.id); }
  }, [user,businessId,locationId]);

  const activeBusiness = user?.businesses.find(b => b.id === businessId) ?? null;
  const activeLocation = activeBusiness?.locations.find(l => l.id === locationId) ?? null;
  const permissionSet = useMemo(() => new Set(activeBusiness?.permissions ?? []), [activeBusiness]);
  const value = useMemo<AuthContextValue>(() => ({ user,loading,activeBusiness,activeLocation,
    can: permission => permissionSet.has(permission),
    canAny: permissions => permissions.some(permission => permissionSet.has(permission)),
    login: async (email,password) => { await initializeCsrf(); const r=await api.post<{data:AuthUser}>('/auth/login',{email,password}); setUser(r.data.data); },
    logout: async () => { await api.post('/auth/logout'); clearWorkspaceContext(); setUser(null); setBusinessId(null); setLocationId(null); },
    refreshUser: async () => { const r=await api.get<{data:AuthUser}>('/auth/me'); setUser(r.data.data); },
    selectBusiness: id => { const b=user?.businesses.find(x=>x.id===id); if(!b)return; setBusinessId(id); setActiveBusinessId(id); const loc=b.locations[0]; if(loc){setLocationId(loc.id);setActiveLocationId(loc.id);} },
    selectLocation: id => { if(!activeBusiness?.locations.some(l=>l.id===id))return; setLocationId(id); setActiveLocationId(id); },
  }),[user,loading,activeBusiness,activeLocation,permissionSet]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
export function useAuth(){const value=useContext(AuthContext);if(!value)throw new Error('useAuth must be used inside AuthProvider');return value;}
