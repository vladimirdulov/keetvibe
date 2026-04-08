# TODO — KeetVibe Development

## Active Tasks

### StreamHub (Go) — Code Review Fixes

- [x] t001 Fix CORS/Origin validation in WebSocket upgrader @vladimirdulov #security ~1h
- [x] t002 Implement JWT authentication on WebSocket connections @vladimirdulov #security ~2h
- [x] t003 Fix race condition in Room.Broadcast() method @vladimirdulov #bug ~1h
- [x] t004 Add rate limiting for WebSocket connections @vladimirdulov #security ~2h
- [x] t005 Add input validation (roomID, userID) @vladimirdulov #security ~1h
- [ ] t006 Remove or complete SFU implementation @vladimirdulov #cleanup ~2h
- [ ] t007 Implement graceful shutdown for Hub @vladimirdulov #perf ~1h
- [x] t008 Fix memory leak - cleanup rooms after all clients leave @vladimirdulov #perf ~1h
- [ ] t009 Add proper error handling with error propagation @vladimirdulov #refactor ~1h
- [ ] t010 Secure internal API endpoints @vladimirdulov #security ~1h

### API (PHP/Symfony) — Code Review Fixes

- [x] t011 Fix SQL injection in ClickHouseService @vladimirdulov #security ~2h
- [x] t012 Implement login endpoint with JWT @vladimirdulov #feature ~2h
- [x] t013 Add analytics authorization checks @vladimirdulov #security ~1h
- [x] t014 Add room access control (participant/host) @vladimirdulov #security ~1h
- [x] t015 Implement logout/token invalidation @vladimirdulov #feature ~1h
- [x] t016 Fix XSS in chat message response @vladimirdulov #security ~1h
- [x] t017 Add rate limiting on endpoints @vladimirdulov #security ~2h
- [x] t018 Remove hardcoded credentials from ClickHouseService @vladimirdulov #security ~30m
- [x] t019 Fix N+1 queries in room and chat serialization @vladimirdulov #perf ~2h
- [x] t020 Add proper error logging in WebSocketNotifier @vladimirdulov #refactor ~1h
- [x] t021 Fix silent failure on invalid replyToId @vladimirdulov #bug ~30m
- [x] t022 Add message moderation (delete, report, mute) @vladimirdulov #feature ~2h
- [x] t023 Add room status transition validation @vladimirdulov #bug ~1h
- [ ] t024 Extract duplicate UUID validation to trait @vladimirdulov #refactor ~1h

### Frontend (Vue.js) — Code Review Fixes

- [x] t025 Fix XSS in chat messages (sanitize user content) @vladimirdulov #security ~1h
- [ ] t026 Fix XSS in room titles and descriptions @vladimirdulov #security ~1h
- [ ] t027 Move auth tokens to httpOnly secure cookies @vladimirdulov #security ~2h
- [ ] t028 Add authentication requirement for RoomViewer @vladimirdulov #security ~1h
- [ ] t029 Fix sensitive data in WebSocket URL @vladimirdulov #security ~1h
- [ ] t030 Add user feedback on API errors @vladimirdulov #bug ~1h
- [ ] t031 Implement lowerHand function (host can lower viewer hands) @vladimirdulov #bug ~1h
- [ ] t032 Use VideoControls component in RoomHost/RoomViewer @vladimirdulov #cleanup ~30m
- [ ] t033 Fix reactivity issues in Login/Register pages @vladimirdulov #bug ~1h
- [ ] t034 Add TypeScript strict typing (remove `any`) @vladimirdulov #refactor ~2h
- [ ] t035 Remove duplicate router files @vladimirdulov #cleanup ~30m
- [ ] t036 Add WebSocket reconnection logic @vladimirdulov #perf ~1h
- [ ] t037 Add CSRF protection @vladimirdulov #security ~1h
- [ ] t038 Fix whiteboard clear (broadcast to viewers) @vladimirdulov #bug ~1h
- [ ] t039 Add virtual scrolling for chat @vladimirdulov #perf ~2h
- [ ] t040 Implement session refresh token flow @vladimirdulov #feature ~2h

### Infrastructure (K8s / Cloudron) — Improvements

- [ ] t041 Replace placeholder secrets with proper values @vladimirdulov #security #infra ~1h
- [ ] t042 Add PodDisruptionBudget for API and stream-hub @vladimirdulov #perf #infra ~1h
- [ ] t043 Add resource limits to all containers @vladimirdulov #perf #infra ~30m
- [ ] t044 Add vertical Pod autoscaler (VPA) recommendations @vladimirdulov #perf #infra ~1h
- [ ] t045 Add Ingress TLS configuration @vladimirdulov #security #infra ~1h
- [ ] t046 Add network policy for database access restrictions @vladimirdulov #security #infra ~1h
- [ ] t047 Add pod anti-affinity for high availability @vladimirdulov #perf #infra ~1h
- [ ] t048 Add persistent volume backup strategy @vladimirdulov #perf #infra ~1h
- [ ] t049 Add Grafana/Prometheus monitoring @vladimirdulov #perf #infra ~2h
- [ ] t050 Add backup/restore scripts for PostgreSQL @vladimirdulov #perf #infra ~2h
- [ ] t051 Fix Cloudron manifest (add RabbitMQ, ClickHouse, MinIO) @vladimirdulov #bug #infra ~1h
- [ ] t052 Add Cloudron healthCheckPath endpoint @vladimirdulov #cleanup #infra ~30m

---

## Priority Order

### StreamHub (Go)
1. **t001, t002** — Critical security issues
2. **t003** — Race condition bug
3. **t004, t005, t010** — Security improvements
4. **t006, t007, t008, t009** — Code quality & cleanup

### API (PHP)
1. **t011, t012, t013** — Critical (SQL injection, login, analytics auth)
2. **t014, t015** — High (access control, logout)
3. **t016, t017, t018** — Security
4. **t019-t024** — Code quality & features

### Frontend (Vue.js)
1. **t025, t026, t027, t028, t029** — Critical (XSS, auth, sensitive data)
2. **t030, t031** — High (error feedback, broken features)
3. **t032-t037** — Medium (cleanup, typing, reconnection)
4. **t038-t040** — Low (features, performance)

### Infrastructure (K8s/Cloudron)
1. **t041, t045, t046** — Critical (secrets, TLS, network policies)
2. **t042, t043, t047** — High (HA, resource limits)
3. **t044, t048-t050** — Medium (monitoring, backup)
4. **t051, t052** — Low (Cloudron fixes)

## Tags

- `#security` — Security-related
- `#bug` — Bug fix
- `#perf` — Performance
- `#cleanup` — Dead code / cleanup
- `#refactor` — Code quality
- `#feature` — New feature
- `#infra` — Infrastructure

## Notes

- See code review report for details
- Run `aidevops status` to check project status