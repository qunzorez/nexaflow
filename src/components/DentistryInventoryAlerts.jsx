import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../services/api.js";

export default function DentistryInventoryAlerts() {
  const [alerts, setAlerts] = useState(null);
  useEffect(() => {
    let active = true;
    const refresh = () => api("/api/dentistry-erp.php?action=alerts").then(r => { if (active && r.ok) setAlerts(r); });
    refresh(); const timer = window.setInterval(refresh, 60000); window.addEventListener("focus", refresh);
    return () => { active = false; window.clearInterval(timer); window.removeEventListener("focus", refresh); };
  }, []);
  if (!alerts || (!alerts.recommendations.length && !alerts.expiring.length)) return null;
  return <aside className="team-notice" style={{ background: "#fff8e9", border: "1px solid #efd8a8", borderRadius: 12, padding: "14px 18px", display: "flex", gap: 16, flexWrap: "wrap", alignItems: "center" }}>
    <strong>Склад стоматології</strong>
    {alerts.recommendations.length > 0 && <span>Потребують закупівлі: {alerts.recommendations.length}</span>}
    {alerts.expiring.length > 0 && <span>Партії з терміном до 30 днів або прострочені: {alerts.expiring.length}</span>}
    <Link className="outline-btn" to="/dentistry/inventory?tab=stock">Відкрити склад</Link>
  </aside>;
}
