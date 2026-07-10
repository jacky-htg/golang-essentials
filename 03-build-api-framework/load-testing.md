# Load Testing

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/load-testing](https://github.com/jacky-htg/workshop/tree/main/load-testing)

## 1. Konsep dasar

### Apa itu Load Testing?

Load testing adalah praktik mensimulasikan lalu lintas pengguna (traffic) ke sistem untuk mengukur performa dan stabilitas aplikasi di bawah beban tertentu. Tujuannya adalah **memastikan aplikasi dapat menangani jumlah pengguna yang diharapkan tanpa degradation performa yang signifikan.**

```text
┌───────────────────────────────────────────────────────────┐
│  LOAD TESTING                                             │
│                                                           │
│  "Berapa banyak pengguna yang bisa dihandle server        │
│   sebelum response time menjadi tidak dapat diterima?"    │
│                                                           │
│  Contoh:                                                  │
│  ✅ 10 user → response time 150ms                         │
│  ✅ 50 user → response time 200ms                         │
│  ⚠️  100 user → response time 500ms (threshold!)          │
│  ❌ 150 user → response time 1.5s (overload!)             │
└───────────────────────────────────────────────────────────┘
```

### Mengapa Penting?

| Manfaat | Penjelasan | Dampak Bisnis |
|---------|------------|---------------|
| Mencegah Outage | Mengetahui batas kapasitas sebelum server down | ⚠️ Downtime = Loss of revenue |
| Capacity Planning | Menentukan kapan perlu scaling | 💰 Efisiensi biaya infrastruktur |
| User Experience | Memastikan response time tetap cepat | 😊 Retensi user meningkat |
| Confidence Deploy | Mengetahui impact perubahan kode | 🚀 Deploy lebih aman |
| SLA Compliance | Memastikan meeting Service Level Agreement | 📋 Kepuasan pelanggan |

**Contoh Kasus :**

```text
Flash Sale:
- Normal: 100 RPS (response time 200ms)
- Flash Sale: 1,000 RPS (response time 5s!) ❌
- User tidak bisa checkout → Loss of revenue

Dengan load testing:
- Diketahui batas di 300 RPS
- Auto-scaling di 250 RPS
- Flash Sale tetap lancar ✅
```

### Jenis-jenis Load Test

| Jenis | Tujuan | Contoh Skenario | Durasi |
|-------|--------|-----------------|--------|
| Baseline | Mengetahui performa normal | 10 VU, 1 menit | Pendek (1-5 menit) |
| Burst | Menguji lonjakan traffic | 20 → 80 RPS dalam 10 detik | Sedang (5-15 menit) |
| Stress | Mencari titik puncak | Naik bertahap sampai overload | Sedang (10-30 menit) |
| Soak | Menguji stabilitas jangka panjang | 50 RPS selama 1-8 jam | Panjang (1-8 jam) |
| Spike | Menguji lonjakan mendadak | 10 → 100 RPS dalam 5 detik | Pendek (5-10 menit) |

*Visualisasi Jenis Load Test:*

```text
RPS
  ▲
  │                    ┌──┐
  │                    │  │
  │  ────┐             │  │
  │      │       ┌─────┘  └─────
  │      └───────┘
  │
  └─────────────────────────────────────► Waktu

  Baseline:      ──── (stabil rendah)
  Burst:         ┌──┐ (lonjakan cepat)
  Stress:        ╱╲╱╲╱╲ (naik bertahap)
  Soak:          ────────── (stabil panjang)
  Spike:         ││ (lonjakan instan)
  ```

### Metric Penting dalam Load Testing

1. RPS (Request Per Second)

```text
Jumlah request yang berhasil diproses per detik.

Contoh:
- 1.000 request dalam 60 detik = 16.67 RPS
- 4.500 request dalam 60 detik = 75 RPS

✅ Semakin tinggi RPS → semakin baik
❌ RPS yang flatten/konstan → ada bottleneck
```

2. Latency / Response Time

Waktu yang dibutuhkan server untuk merespon request.

| Percentile | Arti | Contoh |
|------------|------|--------|
| p50 (median) | 50% request lebih cepat dari ini | 178ms |
| p90 | 90% request lebih cepat dari ini | 405ms |
| p95 | 95% request lebih cepat dari ini | 510ms |
| p99 | 99% request lebih cepat dari ini | 890ms |

- ✅ Semakin rendah → semakin baik
- ⚠️ Perhatikan p95/p99 (outlier bisa jadi indikasi masalah)

3. Error Rate

```text
Persentase request yang gagal (status 4xx/5xx).

Error Rate = (Total Error / Total Request) × 100%

Contoh:
- 0 error dari 13.461 request = 0% ✅
- 100 error dari 10.000 request = 1% ❌

✅ Target: < 1% (0% lebih baik)
❌ Error rate > 1% → perlu investigasi
```

4. Throughput

```text
Jumlah data yang ditransfer per detik.

Data Sent: 219 KB/s
Data Received: 325 KB/s
Total Throughput: ~544 KB/s

✅ Cek apakah bandwidth cukup
❌ Throughput flatten → bandwidth bottleneck
```

## 2. Tools

### k6 (Open Source)

k6 adalah tools load testing modern yang dikembangkan oleh Grafana Labs.

```text
┌───────────────────────────────────────────────────────────┐
│  K6 OVERVIEW                                              │
│                                                           │
│  ✅ Open source & free                                    │
│  ✅ Script dengan JavaScript (mudah dipelajari)           │
│  ✅ Performance tinggi (ditulis dalam Go)                 │
│  ✅ Integrasi dengan Grafana + Prometheus                 │
│  ✅ CLI friendly & CI/CD ready                            │
│  ✅ Cloud & self-hosted options                           │
└───────────────────────────────────────────────────────────┘
```

**Instalasi k6**

```bash
# MacOS
brew install k6

# Linux (Ubuntu/Debian)
sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6

# Windows (WSL atau winget)
winget install k6

# Verify
k6 version
```

### Alternatif Tools

| Tools | Kelebihan | Kekurangan | Kapan Pakai |
|-------|-----------|------------|-------------|
| k6 | Modern, script JS, integrasi Grafana | Kurang UI (CLI based) | Tim DevOps/Backend |
| JMeter | UI lengkap, banyak plugin | Berat, Java-based, script kompleks | Tim QA yang terbiasa |
| Gatling | Scala/Java, report bagus | Learning curve tinggi | Tim yang pakai Scala |
| Locust | Python-based, mudah | Kurang performant | Tim Python |

### Kenapa Pilih k6?

1. ✅ Mudah dipelajari (JavaScript basic)
2. ✅ Performa tinggi (Go-based)
3. ✅ Cloud native (CI/CD ready)
4. ✅ Integrasi mudah (Grafana, Prometheus, Datadog)
5. ✅ Community besar & aktif
6. ✅ Script portable (bisa run di mana saja)

## 3 Skenario Testing (Studi Kasus)

### Studi Kasus: API Motorku X

```text
Aplikasi: Motorku X (Mobile App)
Endpoint: 
  - /api/config/device
  - /api/user/blocked
  - /api/config

Tujuan:
  1. Mengetahui rata-rata latency di berbagai beban
  2. Menentukan kapasitas maksimum server
  3. Memberikan rekomendasi scaling

Lingkungan: 
  - Staging: 1 server
  - Database: PostgreSQL (shared)
  - Framework: PHP Laravel
```

### Struktur Script k6

```javascript
// scenario.js - Konfigurasi dasar
import http from 'k6/http';
import { check, sleep } from 'k6';

export const _options = {
  scenarios: {
    // 1️⃣ Baseline (10 VU, 1 menit)
    baseline_vus_10: {
      executor: 'constant-vus',
      vus: 10,
      duration: '1m',
      exec: 'baseline',
    },

    // 2️⃣ Burst Traffic (Target 75 RPS)
    burst_traffict: {
      executor: 'ramping-arrival-rate',
      startTime: '1m30s',
      timeUnit: '1s',
      stages: [
        { target: 20, duration: '30s' },  // Warm up
        { target: 75, duration: '10s' },  // Burst peak
        { target: 75, duration: '30s' },  // Hold peak
        { target: 20, duration: '20s' },  // Cool down
      ],
      preAllocatedVUs: 30,
      maxVUs: 80,
      exec: 'burstTraffic',
    },
  },

  thresholds: {
    http_req_failed: ['rate<0.01'],           // Error rate < 1%
    http_req_duration: [
      'p(50)<200',   // 50% request < 200ms
      'p(95)<500',   // 95% request < 500ms
      'p(99)<1000',  // 99% request < 1000ms
    ],
  },
};

// Headers & Helper Functions
export function headers(TOKEN, IDENTITY) {
  return {
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Content-Type': 'application/json',
      'identity-key': IDENTITY,
    },
  };
}

// POST Request - Baseline (dengan think time)
export function _post_baseline(URL, TOKEN, IDENTITY, PAYLOAD) {
  const body = JSON.stringify(PAYLOAD);
  const res = http.post(URL, body, headers(TOKEN, IDENTITY));
  check(res, { 'status is 200': (r) => r.status === 200 });
  sleep(0.2); // Simulasi user think time (200ms)
}

// POST Request - Burst (tanpa think time)
export function _post_burstTraffic(URL, TOKEN, IDENTITY, PAYLOAD) {
  const body = JSON.stringify(PAYLOAD);
  const res = http.post(URL, body, headers(TOKEN, IDENTITY));
  check(res, { 'status is 200': (r) => r.status === 200 });
}
```

### Script Single Endpoint

```javascript
// config-only.js - Test endpoint /api/config
import { _options, _post_baseline, _post_burstTraffic } from "./scenario.js";

const BASE_URL = __ENV.BASE_URL || 'https://api-motorkux.astra-motor.co.id';
const TOKEN = __ENV.TOKEN || '';
const URL = `${BASE_URL}/api/config`;

export const options = _options;

export function baseline() {
  _post_baseline(URL, TOKEN, 'MCC', {});
}

export function burstTraffic() {
  _post_burstTraffic(URL, TOKEN, 'MCC', {});
}
```

### Script Gabungan (3 Endpoint)

```javascript
// all-endpoints.js - Test 3 endpoint sekaligus
import { _options, _post_baseline, _post_burstTraffic } from "./scenario.js";
import { configDevicePayload } from "./payload/config-device.js";
import { userBlockedPayload } from "./payload/user-blocked.js";

const BASE_URL = __ENV.BASE_URL || 'https://api-motorkux.astra-motor.co.id';
const TOKEN = __ENV.TOKEN || '';

const URL_CONFIG = `/api/config`;
const URL_CONFIG_DEVICE = `/api/config/device`;
const URL_USER_BLOCKED = `/api/user/blocked`;

export const options = _options;

export function baseline() {
  _post_baseline(`${BASE_URL}${URL_CONFIG}`, TOKEN, 'MCC', {});
  _post_baseline(`${BASE_URL}${URL_CONFIG_DEVICE}`, TOKEN, 'PVR', configDevicePayload);
  _post_baseline(`${BASE_URL}${URL_USER_BLOCKED}`, TOKEN, 'FKR', userBlockedPayload);
}

export function burstTraffic() {
  _post_burstTraffic(`${BASE_URL}${URL_CONFIG}`, TOKEN, 'MCC', {});
  _post_burstTraffic(`${BASE_URL}${URL_CONFIG_DEVICE}`, TOKEN, 'PVR', configDevicePayload);
  _post_burstTraffic(`${BASE_URL}${URL_USER_BLOCKED}`, TOKEN, 'FKR', userBlockedPayload);
}
```

### Menjalankan Test

```bash
# Single endpoint
k6 run config-only.js

# Gabungan 3 endpoint
k6 run all-endpoints.js

# Dengan environment variable
k6 run -e BASE_URL=https://staging-api.example.com -e TOKEN=your_token all-endpoints.js

# Output ke file JSON
k6 run --summary-export=result.json all-endpoints.js
```

## 4 Analisis Hasil

### Hasil Test: all-endpoints.js (Target 75 RPS)

```text
running (3m00.8s), 00/80 VUs, 4487 complete and 0 interrupted iterations

  █ THRESHOLDS 

    http_req_duration
    ✓ 'p(50)<200' p(50)=178.21ms
    ✗ 'p(95)<500' p(95)=509.94ms
    ✓ 'p(99)<1000' p(99)=889.61ms

    http_req_failed
    ✓ 'rate<0.01' rate=0.00%


  █ TOTAL RESULTS 

    checks_total.......: 13461   74.434005/s
    checks_succeeded...: 100.00% 13461 out of 13461
    
    HTTP
    http_req_duration..............: avg=230.45ms min=104.58ms med=178.21ms max=4.4s
      p(90)=405.15ms p(95)=509.94ms
    http_req_failed................: 0.00% 0 out of 13461
    http_reqs......................: 13461 74.434005/s

    EXECUTION
    dropped_iterations.............: 54    0.298599/s
    iteration_duration.............: avg=773.51ms med=696.74ms p(95)=1.26s
    iterations.....................: 4487  24.811335/s
    vus............................: max=75
    vus_max........................: 80

    NETWORK
    data_received..................: 58 MB 323 kB/s
    data_sent......................: 39 MB 218 kB/s

```

### Interpretasi Metric

1. Throughput / RPS

```text
HTTP Requests: 13,461 requests
Duration: 180.8 detik (3m00.8s)

RPS = 13,461 / 180.8 = 74.43 RPS

✅ 74.43 RPS tercapai (target 75 RPS hampir tercapai)
⚠️ Ada 54 dropped iterations (0.30/s) → VU kurang
```

2. Latency / Response Time

```text
Average     : 230.45 ms  ✅ Masih bagus
Median (p50): 178.21 ms ✅ < 200ms
p90         : 405.15 ms  ✅ Masih di bawah 500ms
p95         : 509.94 ms  ❌ Melewati threshold 500ms!
p99         : 889.61 ms  ✅ < 1000ms

Interpretasi:
- 90% request masih cepat (< 405ms)
- 5% request mulai lambat (> 509ms) → mulai ada queueing
- Masih ada outlier sampai 4.4s → perlu investigasi
```

3. Error Rate

Error rate: 0% dari 13,461 request

- ✅ Sempurna! Tidak ada error
- ✅ Server masih bisa memproses semua request

4. Concurrent Users & Iterations

```text
Iterations              : 4,487 (1 iterasi = 3 request = 3 endpoint)
Iterations/s            : 24.81
Iteration duration avg  : 773.51ms

Concurrent Users = (24.81 × 773.51) / 1000 = 19.19 users

Interpretasi:
- Server mampu menangani ~19 user aktif bersamaan
- Pada puncak (P95 iteration = 1.26s): ~31 user aktif
```

### Threshold & Alert

Threshold yang Direkomendasikan

```javascript
thresholds: {
  // Error rate harus < 1%
  http_req_failed: ['rate<0.01'],
  
  // Response time target
  http_req_duration: [
    'p(50)<200',    // Median < 200ms
    'p(90)<400',    // 90% request < 400ms
    'p(95)<500',    // 95% request < 500ms
    'p(99)<1000',   // 99% request < 1s
  ],
  
  // RPS target (opsional)
  http_reqs: ['rate>50'],  // Minimal 50 RPS
  
  // VU tidak terlalu banyak
  vus: ['value<100'],  // Max VU < 100
}
```

Alert Level

| Level | Kondisi | Tindakan |
|-------|---------|----------|
| 🟢 OK | P95 < 400ms, Error 0% | Normal operation |
| 🟡 Warning | P95 > 450ms atau Error > 0.5% | Investigasi, siap scaling |
| 🔴 Critical | P95 > 500ms atau Error > 1% | Scaling segera |
| 🚨 Emergency | P95 > 1s atau Error > 5% | Emergency response |

### Capacity Planning

#### Perbandingan 3 Skenario

| Metric | Target 60 RPS | Target 75 RPS | Target 80 RPS | Analisis |
|--------|---------------|---------------|---------------|----------|
| RPS | 63.04 | 74.43 | 74.90 | Stabil di ~74 RPS |
| Avg RT | 191.51 ms | 230.45 ms | 274.57 ms | Baik, mulai naik |
| P50 | 157.68 ms | 178.21 ms | 193.94 ms | ✅ < 200ms |
| P95 | 377.64 ms | 509.94 ms | 642.11 ms | ❌ Melewati threshold |
| P99 | 697.33 ms | 889.61 ms | 1.18s | Mulai melewati |
| Dropped | 77 | 54 | 238 | Meningkat di 80 RPS |
| Status | ✅ Semua lolos | ⚠️ P95 gagal | ❌ P95 & P99 gagal | Kapasitas: ~74 RPS |

#### Kapasitas Maksimum

```text
Dari hasil test, kapasitas maksimum server adalah ~74 RPS

Kapasitas Stabil (Safe) :  50-55 RPS (P95 < 400ms)
Kapasitas Moderate      :  55-65 RPS (P95 400-450ms)
Kapasitas Peak          :  65-74 RPS (P95 450-500ms)
⚠️  Overload            :   > 74 RPS (P95 > 500ms)

Jika tidak memperhitungkan bottleneck connection, kita bisa memberi saran Production:
- Target maksimum: 55 RPS (safe)
- Alert jika > 60 RPS
- Auto-scaling jika > 65 RPS
```

## 5 Ekstrapolasi Staging → Production

### Mengapa Ekstrapolasi Sulit?

```text
┌───────────────────────────────────────────────────────────----─┐
│  STAGING                    PRODUCTION                         │
│  ┌─────────┐                ┌─────────-------┐                 │
│  │ Server  │                │ LB             │                 │
│  │  (1x)   │                │ ┌─-┐ ┌-─┐ ┌-─┐ │ 3x              │
│  └─────────┘                │ │S1│ │S2│ │S3│ │                 │
│                             │ └─-┘ └─-┘ └─-┘ │                 │
|                             └----------------┘                 |
│  Capacity: 74 RPS            Capacity: ???                     │
│                                                                │
│  ❌ 74 × 3 = 222 RPS?        ❌ Tidak sesederhana itu!          │
└───────────────────────────────────────────────────────────----─┘
```

### Faktor-faktor yang Mempengaruhi

1. Database - Faktor Terbesar!

```text
┌──────────────────────────────────────────────────────────┐
│                    SHARED DATABASE                       │
│                    ┌──────────────┐                      │
│                    │   Database   │                      │
│                    │   (1 Server) │                      │
│                    └──────┬───────┘                      │
│                           │                              │
│         ┌─────────────────┼─────────────────┐            │
│         │                 │                 │            │
│    ┌────▼────┐      ┌─────▼────-─┐    ┌─────▼─────-┐     │
│    │ Service │      │  Service   │    │  Service   │     │
│    │    A    │      │    B       │    │    C       │     │
│    │ (Diuji) │      │(Production)│    │(Production)│     │
│    └─────────┘      └───────────-┘    └───────────-┘     │
│                                                          │
│  ❌ Database capacity dibagi untuk semua service!        │
│  ❌ Service B & C tetap beroperasi saat testing          │
└──────────────────────────────────────────────────────────┘
```

Contoh Perhitungan:

```text
Staging:
- Service A (diuji): 74 RPS → 370 QPS (5 queries/request)
- Service B: OFFLINE
- Service C: OFFLINE
→ Total DB QPS: 370 QPS

Production:
- Service A: 50 RPS (aktual)
- Service B: 40 RPS (aktual)
- Service C: 30 RPS (aktual)
→ Total DB QPS: (50+40+30) × 5 = 600 QPS

→ Database di production harus handle 600 QPS
→ Staging hanya handle 370 QPS
→ Staging test TIDAK valid tanpa memperhitungkan service lain!
```

2. Connection Pool

DATABASE CONNECTION POOL (Max: 100)

```text
Staging:
- Service A: 80 connections
- Service B: 0 connections
- Service C: 0 connections
→ Available: 80 connections ✅

Production:
- Service A: 40 connections
- Service B: 30 connections
- Service C: 25 connections
→ Total: 95 connections (hampir habis!) ❌

→ Service A di production hanya dapat 40 connections
→ Kapasitas turun drastis!
```

3. Load Balancer Overhead

| Jumlah Server | Overhead LB |Faktor Efisiensi |
|---------------|-------------|-----------------|
| 1 server | 0% | 1.0 |
| 2 server | 5-10% | 0.90-0.95 |
| 3 server | 10-15% | 0.85-0.90 |
| 5+ server | 15-25% | 0.75-0.85 |

4. Session Management

| Tipe | Overhead | Faktor Koreksi |
|------|----------|----------------|
| Stateless (JWT) | 2-5% | 0.95-0.98 |
| Stateful (Redis) | 10-15% | 0.85-0.90 |
| Stateful (In-memory) | 15-25% | 0.75-0.85 |

5. Network Bandwidth

```text
Staging   : 1 Gbps
Production: 1 Gbps (sama)

Data sent     : 218 KB/s
Data received : 323 KB/s
Total         : ~544 KB/s

→ Bandwidth masih aman (hanya 0.5% dari 1 Gbps)
→ Bukan bottleneck
```

### Formula Kalkulasi Ekstrapolasi

Formula Dasar

```text
Prod Capacity = Staging Capacity × Multiplier × Faktor Koreksi
```

Faktor Koreksi Lengkap

```javascript
export function calculateProductionCapacity(stagingData, prodConfig) {
    // === AMBIL DATA DARI PARAMETER YANG BENAR ===
    const { stagingMaxRPS, stagingP95AtMax } = stagingData;
    
    const {
        prodServerCount,
        dbType,
        otherServicesRPS,
        queriesPerRequest,
        dbMaxQPS,
        dbPoolSize,
        connectionsPerService,
        hasLoadBalancer,
        sessionType,
        conservativeFactor = 0.9,
    } = prodConfig;
    
    // === VALIDASI ===
    if (!stagingMaxRPS || stagingMaxRPS <= 0) {
        throw new Error('stagingMaxRPS must be positive');
    }
    if (!stagingP95AtMax || stagingP95AtMax <= 0) {
        throw new Error('stagingP95AtMax must be positive');
    }
    if (!prodServerCount || prodServerCount <= 0) {
        throw new Error('prodServerCount must be positive');
    }
    if (dbMaxQPS <= 0 || dbPoolSize <= 0) {
        throw new Error('dbMaxQPS and dbPoolSize must be positive');
    }
    if (conservativeFactor <= 0 || conservativeFactor > 1) {
        throw new Error('conservativeFactor must be between 0 and 1');
    }
    
    // 1. Base multiplier
    let multiplier = prodServerCount;
    
    // 2. Database QPS capacity
    const otherServiceQPS = otherServicesRPS * queriesPerRequest;
    const availableQPS = dbMaxQPS - otherServiceQPS;
    
    if (availableQPS <= 0) {
        throw new Error(
            `Database overloaded! Other services already use ${otherServiceQPS} QPS, ` +
            `leaving only ${availableQPS} QPS for this service.`
        );
    }
    
    const dbCapacity = availableQPS / queriesPerRequest;
    
    // 3. Connection pool capacity
    const otherConnections = (otherServicesRPS / 10) * connectionsPerService;
    const availableConnections = dbPoolSize - otherConnections;
    
    if (availableConnections <= 0) {
        throw new Error(
            `Connection pool exhausted! Other services use ${otherConnections} connections, ` +
            `leaving ${availableConnections} for this service.`
        );
    }
    
    const connectionCapacity = availableConnections * (1000 / stagingP95AtMax);
    
    // 4. Load balancer factor
    if (hasLoadBalancer) {
        multiplier *= (prodServerCount > 3) ? 0.85 : 0.9;
    }
    
    // 5. Session factor
    multiplier *= (sessionType === 'stateful') ? 0.85 : 0.95;
    
    // 6. Database factor
    const dbFactors = {
        'single': 0.6,
        'replica': 0.8,
        'sharded': 0.95,
    };
    multiplier *= dbFactors[dbType] || 0.7;
    
    // 7. Conservative factor (safety)
    multiplier *= conservativeFactor;
    
    // 8. Hitung kapasitas
    const serverCapacity = stagingMaxRPS * multiplier;
    
    // 9. Ambil minimum (bottleneck)
    const capacity = Math.min(
        serverCapacity,
        dbCapacity,
        connectionCapacity
    );
    
    if (isNaN(capacity) || !isFinite(capacity)) {
        throw new Error(
            `Invalid capacity calculation: ` +
            `serverCapacity=${serverCapacity}, ` +
            `dbCapacity=${dbCapacity}, ` +
            `connectionCapacity=${connectionCapacity}`
        );
    }
    
    return {
        estimatedMaxRPS: Math.round(capacity),
        estimatedStableRPS: Math.round(capacity * 0.7),
        multiplier: Math.round(multiplier * 100) / 100,
        dbCapacity: Math.round(dbCapacity),
        connectionCapacity: Math.round(connectionCapacity),
        breakdown: {
            serverCapacity: Math.round(serverCapacity),
            dbCapacity: Math.round(dbCapacity),
            connectionCapacity: Math.round(connectionCapacity),
            bottleneck: capacity === serverCapacity ? 'server' :
                       capacity === dbCapacity ? 'database' : 'connection'
        }
    };
}
```

Contoh Perhitungan

```javascript
import { calculateProductionCapacity } from './calc_form.js';

const withOtherSvc = calculateProductionCapacity(
    { 
        stagingMaxRPS: 74,
        stagingP95AtMax: 509
    },
    {
        prodServerCount: 3,
        dbType: 'single',
        otherServicesRPS: 70,
        queriesPerRequest: 5,
        dbMaxQPS: 1000,
        dbPoolSize: 100,
        connectionsPerService: 10,
        hasLoadBalancer: true,
        sessionType: 'stateless',
    }
);

const onlyThisSvc = calculateProductionCapacity(
    { 
        stagingMaxRPS: 74,
        stagingP95AtMax: 509
    },
    {
        prodServerCount: 3,
        dbType: 'single',
        otherServicesRPS: 0,
        queriesPerRequest: 5,
        dbMaxQPS: 1000,
        dbPoolSize: 100,
        connectionsPerService: 10,
        hasLoadBalancer: true,
        sessionType: 'stateless',
    }
);

const conservative = calculateProductionCapacity(
    { 
        stagingMaxRPS: 74,
        stagingP95AtMax: 509
    },
    {
        prodServerCount: 3,
        dbType: 'single',
        otherServicesRPS: 70,
        queriesPerRequest: 5,
        dbMaxQPS: 1000,
        dbPoolSize: 100,
        connectionsPerService: 10,
        hasLoadBalancer: true,
        sessionType: 'stateless',
        conservativeFactor: 0.7,
    }
);

console.log(withOtherSvc);
console.log(onlyThisSvc);
console.log(conservative);

/*
Output:
{
  estimatedMaxRPS: 59,
  estimatedStableRPS: 41,
  multiplier: 1.39,
  dbCapacity: 130,
  connectionCapacity: 59,
  breakdown: {
    serverCapacity: 102,
    dbCapacity: 130,
    connectionCapacity: 59,
    bottleneck: 'connection'
  }
}
{
  estimatedMaxRPS: 102,
  estimatedStableRPS: 72,
  multiplier: 1.39,
  dbCapacity: 200,
  connectionCapacity: 196,
  breakdown: {
    serverCapacity: 102,
    dbCapacity: 200,
    connectionCapacity: 196,
    bottleneck: 'server'
  }
}
{
  estimatedMaxRPS: 59,
  estimatedStableRPS: 41,
  multiplier: 1.39,
  dbCapacity: 130,
  connectionCapacity: 59,
  breakdown: {
    serverCapacity: 102,
    dbCapacity: 130,
    connectionCapacity: 59,
    bottleneck: 'connection'
  }
}
*/
```

### Rekomendasi Kapasitas Production

| Skenario | Staging | Production (Est.) | Target Aman |
|----------|---------|-------------------|-------------|
| Service A Only | 74 RPS | 102 RPS | 72 RPS |
| Dengan Service Lain | 74 RPS | 59 RPS | 41 RPS |
| Conservative | 74 RPS | 59 RPS | 41 RPS |


Rekomendasi Production:

```text
✅ Target Aman: 50 RPS
🟡 Alert: > 60 RPS
🔴 Scale: > 65 RPS
🚨 Emergency: > 70 RPS
```

## 6 Rekomendasi & Action Items

### Alert Configuration

Prometheus + Alertmanager

```yaml
# prometheus-alerts.yml
groups:
  - name: api_alerts
    rules:
      # 1. Response Time Alert
      - alert: HighResponseTime
        expr: histogram_quantile(0.95, sum(rate(http_request_duration_seconds_bucket[5m])) by (le, endpoint)) > 0.5
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High response time on {{ $labels.endpoint }}"
          description: "P95 response time is {{ $value }}s (threshold: 0.5s)"

      # 2. Error Rate Alert
      - alert: HighErrorRate
        expr: sum(rate(http_requests_total{status=~"5.."}[5m])) / sum(rate(http_requests_total[5m])) > 0.01
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "High error rate detected"
          description: "Error rate is {{ $value }}% (threshold: 1%)"

      # 3. High RPS Alert
      - alert: HighRPS
        expr: sum(rate(http_requests_total[1m])) > 60
        for: 3m
        labels:
          severity: warning
        annotations:
          summary: "High RPS detected"
          description: "Current RPS is {{ $value }} (threshold: 60)"

      # 4. Dropped Requests Alert
      - alert: DroppedRequests
        expr: increase(k6_dropped_iterations_total[5m]) > 10
        for: 2m
        labels:
          severity: warning
        annotations:
          summary: "Dropped requests detected"
          description: "{{ $value }} requests dropped in the last 5 minutes"

      # 5. Database Connection Alert
      - alert: DatabaseConnections
        expr: pg_stat_database_numbackends > 80
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Database connections high"
          description: "Current connections: {{ $value }} (threshold: 80)"
```

### Scaling Strategy

Level 1: Vertical Scaling (Scale Up)

```text
┌─────────────────────────────────────────────────────────┐
│  VERTICAL SCALING                                       │
│                                                         │
│  Sebelum:                 Sesudah:                      │
│  ┌──────────────┐        ┌──────────────┐               │
│  │ CPU: 4 core  │  ──▶   │ CPU: 8 core  │               │
│  │ RAM: 8 GB    │        │ RAM: 16 GB   │               │
│  └──────────────┘        └──────────────┘               │
│                                                         │
│  ✅ Mudah implementasi                                  │
│  ❌ Ada batas maksimum                                  │
│  ❌ Downtime saat upgrade                               │
└─────────────────────────────────────────────────────────┘
```

Kapan Pakai:
- RPS meningkat 20-30%
- Response time mulai naik
- CPU > 70% atau RAM > 80%

Level 2: Horizontal Scaling (Scale Out)

```text
┌────────────────────────────────────────────────────────┐
│  HORIZONTAL SCALING                                    │
│                                                        │
│  ┌─────────┐     ┌─────────┐     ┌─────────┐           │
│  │ Server1 │     │ Server2 │     │ Server3 │           │
│  └────┬────┘     └────┬────┘     └────┬────┘           │
│       └───────────────┼───────────────┘                │
│                       │                                │
│                  ┌────▼────┐                           │
│                  │   LB    │                           │
│                  └─────────┘                           │
│                                                        │
│  ✅ Tanpa batas (selama ada budget)                    │
│  ✅ Zero downtime (rolling update)                     │
│  ❌ Lebih kompleks                                     │
│  ❌ Database tetap 1x (bottleneck!)                    │
└────────────────────────────────────────────────────────┘
```

Kapan Pakai:
- RPS meningkat > 50%
- Vertical scaling sudah maksimal
- Perlu high availability

Level 3: Database Scaling

```text
┌─────────────────────────────────────────────────────────┐
│  DATABASE SCALING                                       │
│                                                         │
│  Tahap 1: Read Replica                                  │
│  ┌─────────┐  ┌─────────┐                               │
│  │ Master  │──▶│ Replica │  (Read-only)                 │
│  └─────────┘  └─────────┘                               │
│                                                         │
│  Tahap 2: Sharding                                      │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐                  │
│  │ Shard 1 │  │ Shard 2 │  │ Shard 3 │                  │
│  │ (User  )│  │ (Order )│  │ (Prod  )│                  │
│  └─────────┘  └─────────┘  └─────────┘                  │
│                                                         │
│  ✅ Bottleneck utama solved                             │
│  ❌ Kompleksitas tinggi                                 │
│  ❌ Application code changes                            │
└─────────────────────────────────────────────────────────┘
```

Kapan Pakai:
- Database CPU > 70%
- Connection pool hampir habis
- Query time meningkat

### Monitoring

Dashboard Grafana yang Direkomendasikan

```yaml
# 1. API Performance Dashboard
Panels:
  - RPS (Request Per Second)
  - Response Time (p50, p90, p95, p99)
  - Error Rate
  - Throughput (data sent/received)

# 2. Server Resource Dashboard
Panels:
  - CPU Usage (%)
  - Memory Usage (%)
  - Network I/O
  - Disk I/O

# 3. Database Dashboard
Panels:
  - Connections
  - Query Rate (QPS)
  - Query Duration
  - Lock Contention

# 4. Load Testing Dashboard
Panels:
  - Active VUs
  - Dropped Iterations
  - Iteration Duration
  - Iterations/s
```

Metric yang Harus Dimonitor

| Metric | Target | Alert |
|--------|--------|-------|
| RPS | < 50 | > 60 |
| P95 Response Time | < 400ms | > 450ms |
| Error Rate | 0% | > 0.5% |
| CPU | < 60% | > 70% |
| Memory | < 70% | > 80% |
| DB Connections | < 60 | > 80 |
| DB Query Time | < 100ms | > 200ms |
| Disk Usage | < 70% | > 80% |

### Action Items Checklist

Immediate (Hari Ini)

```text
□ Setup alert untuk P95 response time > 450ms
□ Setup alert untuk error rate > 0.5%
□ Setup alert untuk RPS > 60
□ Review database connection pool size
□ Review application thread pool size
```

Short-term (Minggu Ini)

```text
□ Implementasi caching untuk endpoint berat
□ Optimasi query database (indexing)
□ Upgrade connection pool
□ Setup auto-scaling configuration
□ Dokumentasi capacity planning
```

Medium-term (Bulan Ini)

```text
□ Implementasi Redis caching
□ Database read-replica (jika perlu)
□ Load balancer configuration
□ Soak test untuk cek stabilitas
□ Regular load testing di staging
```

Long-term (Quarter)

```text
□ Microservices architecture (pisahkan endpoint berat)
□ Database sharding strategy
□ Full observability stack (Prometheus + Grafana + Loki)
□ Chaos engineering
□ Regular capacity review
```

## Ringkasan

### Yang Sudah Kita Pelajari

| Topik | Poin Kunci |
|-------|------------|
| Konsep Dasar | Baseline, Burst, Stress, Soak, Spike |
| Metric Penting | RPS, Latency, Error Rate, Throughput |
| Tools | k6 (recommended), JMeter, Gatling, Locust |
| Skenario | Baseline 10 VU, Burst 75 RPS |
| Analisis | Interpretasi metric, Threshold, Capacity |
| Ekstrapolasi | DB bottleneck, Connection pool, Faktor koreksi |
| Rekomendasi | Alert, Scaling, Monitoring |

### Best Practices

1. ✅ Mulai dengan baseline (ukur performa normal)
2. ✅ Naikkan beban bertahap (temukan titik limit)
3. ✅ Perhatikan p95 (bukan hanya average)
4. ✅ 0% error rate adalah target minimal
5. ✅ Monitor resource saat test (CPU, RAM, DB)
6. ✅ Ekstrapolasi dengan faktor koreksi realistis
7. ✅ Setup alert sebelum deploy ke production
8. ✅ Regular load testing (setiap release)
9. ✅ Simpan hasil test untuk perbandingan
10. ✅ Libatkan tim (Dev + QA + Ops)