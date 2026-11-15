export function getPlanLimit(subscription, key) {
  return subscription?.limits?.find((item) => item.key === key) || null;
}

export function isPlanLimitReached(subscription, key) {
  const item = getPlanLimit(subscription, key);
  return item?.limit != null && Number(item.used) >= Number(item.limit);
}

export function planLimitHint(subscription, key) {
  const item = getPlanLimit(subscription, key);
  if (!item || item.limit == null) return "";
  return `Вичерпано ліміт «${item.label}» у плані «${subscription?.name || ""}» (${item.used} з ${item.limit}). Змініть план у налаштуваннях, щоб продовжити.`;
}
