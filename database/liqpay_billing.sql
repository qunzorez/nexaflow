CREATE TABLE IF NOT EXISTS plan_subscriptions (
    id CHAR(32) NOT NULL PRIMARY KEY,
    owner_id CHAR(32) NOT NULL,
    plan_key VARCHAR(24) NOT NULL,
    module_key VARCHAR(24) NOT NULL DEFAULT 'accounting',
    order_id VARCHAR(64) NOT NULL UNIQUE,
    liqpay_subscription_id VARCHAR(100) NULL,
    liqpay_payment_id VARCHAR(100) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    current_period_end DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plan_subscription_payment (liqpay_payment_id),
    KEY idx_plan_subscription_owner (owner_id, status, current_period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
