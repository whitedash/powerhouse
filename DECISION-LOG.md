# Decision Log — Powerhouse

| Date | Decision | Rationale |
|---|---|---|
| May 2026 | Laravel + Vue SPA | Consistent with MyOrderPad; better suited to application logic than WordPress |
| May 2026 | Laravel Passport for OAuth 2.0 | Central auth server for all Whitedash products |
| May 2026 | MySQL | Consistent with existing stack |
| May 2026 | HTTPS for GitHub remote | No SSH keys configured on dev machine |
| May 2026 | Products table database-driven | Supports adding future products without code deploys |
| May 2026 | Powerhouse never commercialised | It is Apostolos's operating layer, not a product |
| May 2026 | Each product control panel investor-ready | Any product can be spun out independently of Powerhouse |
| May 2026 | Universal customer account via OAuth 2.0 | One Whitedash relationship per customer; brand identity |
| May 2026 | Multi-entity invoicing from day one | Future LTD companies must be supportable without schema changes |
| May 2026 | Commission rules use JSON config | Flexible for all models; new products need no schema changes |
