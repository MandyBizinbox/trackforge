const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';
export function getToken(){ return localStorage.getItem('tfqm_token'); }
export function setToken(token){ token ? localStorage.setItem('tfqm_token', token) : localStorage.removeItem('tfqm_token'); }
export async function api(path, options={}){
  const headers = {'Content-Type':'application/json', ...(options.headers||{})};
  const token=getToken(); if(token) headers.Authorization=`Bearer ${token}`;
  const res = await fetch(`${API_BASE}${path}`, {...options, headers, body: options.body && typeof options.body !== 'string' ? JSON.stringify(options.body) : options.body});
  if(!res.ok){ let msg='Request failed'; try{ const e=await res.json(); msg=e.message || JSON.stringify(e); }catch{} throw new Error(msg); }
  return res.status===204 ? null : res.json();
}
export const queueOfflineAction = (action) => { const q=JSON.parse(localStorage.getItem('tfqm_queue')||'[]'); q.push({...action, queued_at:new Date().toISOString()}); localStorage.setItem('tfqm_queue', JSON.stringify(q)); };
export const getOfflineQueue = () => JSON.parse(localStorage.getItem('tfqm_queue')||'[]');
