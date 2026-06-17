# Changelog

## v0.5.0-beta
Initial public beta.

- **Server Check & Optimize** — sizes streaming settings to your hardware and sets each load balancer's max-users capacity.
- **Auto-Tier** — keeps popular streams always-on and drops rarely/never-used ones to on-demand, from real usage logs.
- **Rebalance** — moves streams off overloaded load balancers onto ones with spare capacity.
- **Smart Placement** — places popular streams on strong servers and idle ones on weak servers.

Every feature has a preview, and optional auto-run via XC_VM's crontab table. Live streams reconnect when moved — preview first.
