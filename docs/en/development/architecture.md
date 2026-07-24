# Architecture

## Telegram Layer

Receives updates through webhook or long polling, manages menus, order flow, payments, and service display.

## Orders And Payments

Orders represent customer intent. Payments represent transactions. Payment approval finalizes provisioning through `PaymentApprovalService`.

## Payment Gateways

Core gateway drivers handle built-in types. Plugin gateway drivers are loaded through the plugin bridge after doctor validation.

## Plugin System

Plugin packages are installed under `var/plugins/{code}`. Runtime support currently focuses on `payment_gateway` plugins.

## VPN Provisioning

VPN panels and inbounds define remote provisioning targets. Services store provisioned client data, subscription URLs, and config links.

### Sanaei / 3x-ui Integration Generations

Sanaei panels have one vendor panel type, `sanaei_3xui`, and an explicit integration generation stored on `VpnPanel.sanaeiIntegrationMode`.

`legacy` is the default for historical and new panels. It is the current production-compatible implementation: username/password or existing session-cookie behavior, the existing inbound-oriented provisioning flow, existing `externalProxy` metadata handling, current link generation, current subscription behavior, and the existing Sanaei API client.

`modern` is reserved for the current official `/panel/api/*` API surface. The modern integration is based on documented Bearer-token automation and the modern domains: authentication, inbounds, clients, hosts, settings, subscription resolution, server, and Xray settings. Phase 1 registers only a skeleton driver and does not implement modern provisioning or delivery operations.

Driver selection is centralized in the VPN driver registry through each driver's `supports()` method. There is no runtime fallback from modern to legacy: if a panel is explicitly configured as modern and an operation is not implemented yet, the modern driver fails with `UnsupportedPanelOperationException`. Migration is per panel and explicit; existing rows with a null `sanaei_integration_mode` column remain legacy.

The EasyAdmin `Sanaei integration` field is currently displayed on the shared VPN panel form for every panel type. Non-Sanaei drivers ignore this property; conditional display can be added later without changing driver resolution.

## Automation

Automation commands sync usage, check expiry, send notifications, suspend services, and expire incomplete orders.

## Admin

EasyAdmin provides operational CRUD, actions, and diagnostics with fa/en and RTL/LTR support.
