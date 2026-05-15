# Admin Guide

The admin panel is built with EasyAdmin and supports Persian and English. Persian renders RTL; English renders LTR.

## Main Areas

- Store: plans, discounts, orders, payments, payment gateways, store payment methods
- VPN: panels, inbounds, and provisioned VPN services
- Users: users and Telegram accounts
- Automation: lifecycle checks and background operational commands
- System: settings, plugins, logs, and operational diagnostics

## Core Concepts

- Plan: a sellable VPN package, including price, duration, and volume.
- Order: a purchase, renewal, or add-traffic intent.
- Payment: a transaction attached to an order.
- VPN Service: the provisioned service after payment approval.
- PaymentGateway: gateway credentials and configuration.
- StorePaymentMethod: a bot-visible payment option linked to one gateway.
- Plugin: an installed extension package. Current runtime support is focused on `payment_gateway`.

## Common Workflows

1. Create a plan with price, duration, volume, and active status.
2. Configure a VPN panel and sync or create inbounds.
3. Configure a payment gateway.
4. Create and activate a StorePaymentMethod for that gateway.
5. For manual payments, review submitted receipts and confirm or reject them.
6. Track orders by status and tracking code.
7. Check bot message logs, payment diagnostics, and panel test commands when a flow fails.

## Language

Use the admin language switcher for `fa` and `en`. Persian UI is RTL and English UI is LTR.
