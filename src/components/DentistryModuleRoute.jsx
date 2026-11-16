import { useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import { api } from "../services/api.js";

export default function DentistryModuleRoute({ children }) {
  const [allowed, setAllowed] = useState(null);
  useEffect(() => {
    let active = true;
    api("/api/client-module.php").then(result => {
      if (active) setAllowed(Boolean(result.ok && result.active_client_module === "dentistry"));
    }).catch(() => { if (active) setAllowed(false); });
    return () => { active = false; };
  }, []);
  if (allowed === null) return <div className="plan-route-loading" aria-label="Завантаження модуля" />;
  return allowed ? children : <Navigate to="/dashboard" replace />;
}
