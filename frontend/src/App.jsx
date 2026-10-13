import React, {useEffect, useState} from 'react';
import { Link, Navigate, Route, Routes, useNavigate } from 'react-router-dom';
import { Bell, Box, CalendarDays, ClipboardCheck, LayoutDashboard, LogOut, Menu, Users } from 'lucide-react';
import { api, setToken, getToken } from './lib/api.js';
import Dashboard from './pages/Dashboard.jsx';
import Equipment from './pages/Equipment.jsx';
import Bookings from './pages/Bookings.jsx';
import BookingDetail from './pages/BookingDetail.jsx';
import NewBooking from './pages/NewBooking.jsx';
import Notifications from './pages/Notifications.jsx';
import UsersPage from './pages/UsersPage.jsx';

export const AppContext = React.createContext(null);

function Login(){
  const nav=useNavigate(); const [email,setEmail]=useState(''); const [password,setPassword]=useState(''); const [error,setError]=useState('');
  async function submit(e){ e.preventDefault(); setError(''); try{ const r=await api('/login',{method:'POST',body:{email,password}}); setToken(r.token); nav('/dashboard'); location.reload(); }catch(err){ setError(err.message); } }
  return <div className="login"><form onSubmit={submit} className="card login-card"><h1>TrackForge QM</h1><p>Store room equipment, bookings and returns.</p>{error && <div className="alert danger">{error}</div>}<label>Email<input value={email} onChange={e=>setEmail(e.target.value)} /></label><label>Password<input type="password" value={password} onChange={e=>setPassword(e.target.value)} /></label><button className="btn primary">Login</button></form></div>
}
function Layout({children}){
  const {user, unread, latestToast, loadNotifications}=React.useContext(AppContext); const nav=useNavigate(); const [open,setOpen]=useState(false);
  function logout(){ api('/logout',{method:'POST'}).finally(()=>{setToken(null); nav('/login');}); }
  useEffect(()=>{ const i=setInterval(loadNotifications,30000); return()=>clearInterval(i); },[]);
  const menu=<><Link to="/dashboard"><LayoutDashboard size={18}/>Dashboard</Link><Link to="/equipment"><Box size={18}/>Equipment</Link><Link to="/bookings"><CalendarDays size={18}/>Bookings</Link><Link to="/notifications"><Bell size={18}/>Notifications {unread>0 && <b className="badge">{unread}</b>}</Link>{['admin','qm'].includes(user?.role)&&<Link to="/users"><Users size={18}/>Users</Link>}</>;
  return <div className="shell"><aside className={open?'open':''}><div className="brand">TrackForge QM</div>{menu}<button onClick={logout} className="ghost"><LogOut size={18}/>Logout</button></aside><main><header><button className="icon" onClick={()=>setOpen(!open)}><Menu/></button><div><b>{user?.name}</b><span>{user?.role}</span></div><Link className="bell" to="/notifications"><Bell/>{unread>0&&<em>{unread}</em>}</Link></header>{latestToast && <div className="toast"><b>{latestToast.title}</b><span>{latestToast.message}</span></div>}{children}</main></div>
}
export default function App(){
  const [user,setUser]=useState(null); const [loading,setLoading]=useState(true); const [unread,setUnread]=useState(0); const [latestToast,setLatestToast]=useState(null); const [seen,setSeen]=useState(new Set(JSON.parse(localStorage.getItem('tfqm_seen_notifications')||'[]')));
  async function loadNotifications(){ if(!getToken()) return; try{ const c=await api('/notifications/unread-count'); setUnread(c.count); const list=await api('/notifications'); const newest=list.data?.find(n=>!n.read_at); if(newest && !seen.has(newest.id)){ setLatestToast(newest); const ns=new Set(seen); ns.add(newest.id); setSeen(ns); localStorage.setItem('tfqm_seen_notifications',JSON.stringify([...ns])); setTimeout(()=>setLatestToast(null),7000); } }catch{} }
  useEffect(()=>{ async function boot(){ if(!getToken()){ setLoading(false); return; } try{ const r=await api('/me'); setUser(r.user); await loadNotifications(); }catch{ setToken(null); } finally{ setLoading(false); } } boot(); },[]);
  if(loading) return <div className="loading">Loading TrackForge QM...</div>;
  return <AppContext.Provider value={{user,setUser,unread,loadNotifications,latestToast}}><Routes><Route path="/login" element={<Login/>}/><Route path="*" element={!user?<Navigate to="/login"/>:<Layout><Routes><Route path="/dashboard" element={<Dashboard/>}/><Route path="/equipment" element={<Equipment/>}/><Route path="/bookings" element={<Bookings/>}/><Route path="/bookings/new" element={<NewBooking/>}/><Route path="/bookings/:id" element={<BookingDetail/>}/><Route path="/notifications" element={<Notifications/>}/><Route path="/users" element={<UsersPage/>}/><Route path="*" element={<Navigate to="/dashboard"/>}/></Routes></Layout>}/></Routes></AppContext.Provider>
}
