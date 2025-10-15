import { useCallback, useEffect, useMemo, useState } from 'react';

export default function useQueryParams() {
  // stato reattivo collegato alla location
  const [queryString, setQueryString] = useState(() => window.location.search);

  // quando cambia la history (tasti back/forward), aggiorna lo stato
  useEffect(() => {
    const onPop = () => setQueryString(window.location.search);
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  // oggetto URLSearchParams ricreato a ogni cambiamento
  const params = useMemo(() => new URLSearchParams(queryString), [queryString]);

  const pushUrl = useCallback((u, { replace = false } = {}) => {
    if (replace) {
      window.history.replaceState({}, '', u.toString());
    } else {
      window.history.pushState({}, '', u.toString());
    }
    // aggiorna lo stato così React rilegge i params senza refresh
    setQueryString(u.search);
  }, []);

  const setParam = useCallback((key, value, { replace = false } = {}) => {
    const u = new URL(window.location.href);
    u.searchParams.set(key, value);
    pushUrl(u, { replace });
  }, [pushUrl]);

  const delParam = useCallback((key, { replace = false } = {}) => {
    const u = new URL(window.location.href);
    u.searchParams.delete(key);
    pushUrl(u, { replace });
  }, [pushUrl]);

  const setParams = useCallback((obj = {}, { replace = false } = {}) => {
    const u = new URL(window.location.href);
    Object.entries(obj).forEach(([k, v]) => {
      if (v === undefined || v === null || v === '') {
        u.searchParams.delete(k);
      } else {
        u.searchParams.set(k, String(v));
      }
    });
    pushUrl(u, { replace });
  }, [pushUrl]);

  return { params, setParam, delParam, setParams };
}
