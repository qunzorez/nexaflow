import { useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import { api } from "../services/api.js";

export default function PlanFeatureRoute({ feature, children }) {
  const [state, setState] = useState("loading");
  useEffect(() => {
    let active = true;
    api("/api/profile.php").then(result => {
      const privilegedPlanBypass = feature !== "accounting" && ["admin", "owner"].includes(result.user?.role);
      if (active) setState(result.ok && (privilegedPlanBypass || (feature === 'support' && Number(result.user?.is_support_agent) === 1) || result.subscription?.features?.[feature]) ? "allowed" : "locked");
    }).catch(() => { if (active) setState("locked"); });
    return () => { active = false; };
  }, [feature]);
  if (state === "loading") return <div className="plan-route-loading" aria-label="Завантаження плану" />;
  if (state === "locked") return <Navigate to="/settings" replace state={{ planFeatureLocked: feature }} />;
  return children;
}
