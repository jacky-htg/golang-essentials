# Bab 22: Cache

Dalam pengembangan aplikasi modern, caching adalah salah satu strategi paling efektif untuk meningkatkan performa dan mengurangi beban database. Buku ini akan memandu Anda melalui implementasi caching yang robust menggunakan **Valkey** (fork dari Redis) dengan bahasa Go.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/22-cache](https://github.com/jacky-htg/workshop/tree/main/22-cache)

## 22.1 Masalah yang Dihadapi

Bayangkan Anda memiliki aplikasi dengan fitur daftar pengguna (List Users) yang dipanggil ribuan kali per detik. Setiap request akan:
1. Query database (`SELECT * FROM users`)
2. Mengirimkan data ke user
3. Memproses query yang sama berulang kali

**Dampak:**
- Database overload
- Response time lambat (100ms+)
- Biaya infrastruktur tinggi

## 22.2 Solusi: Caching

Dengan caching, kita menyimpan hasil query di memory (Valkey/Redis) sehingga:

| **Metrik** | Tanpa Cache | Dengan Cache |
|------------|-------------|--------------|
| **Response Time** | 100ms+ | 1-5ms |
| **Database Load** | 100% | < 10% |
| **Skalabilitas** | Terbatas | Sangat baik |

```text
┌─────────────────────────────────────────────────────────────────────────┐
│  Tanpa Cache:                                                           │
│  User → API → Database (100ms) → Response                               │
│                                                                         │
│  Dengan Cache:                                                          │
│  User → API → Cache (2ms) → Response (CACHE HIT!)                       │
│  User → API → Cache (MISS) → Database (100ms) → Cache → Response        │
└─────────────────────────────────────────────────────────────────────────┘
```

## 22.3 Memilih Cache Server

Redis banyak digunakan sebagai server cache, namnun semenjak redis tidak lagi open source, komunitas opensource membuat valkey yang di-fork dari redis versi 7.2. 

### Mnejalankan Valkey dengan Docker

```bash
docker run -d --name valkey -p 6379:6379 valkey/valkey:9-alpine
```

## 22.4 Memilih Library Go untuk Valkey

| Library | Kelebihan | Kekurangan |
|---------|-----------|------------|
| `valkey-glide` | 
- ✅ Multi-language support (Rust core)
- ✅ AZ Affinity Routing (optimasi biaya cloud)
- ✅ Stable dan enterprise-ready
- ✅ Consisten di semua bahasa | 
- ⚠️ 1 koneksi multiplex per node
- ⚠️ Perlu client terpisah untuk operasi besar |
| `valkey-go` | 
- ✅ Native Go, sangat cepat
- ✅ Auto-pipelining
- ✅ Client-side caching
- ✅ Connection pool support | 
- ⚠️ Hanya untuk Go
- ⚠️ Fitur enterprise terbatas |

**Rekomendasi**
- Cloud (AWS/GCP) -> Gunakan `valkey-glide` (AZ Affinity routing menghemat biaya)
- On premise -> Gunakan `valkey-go` (Lebih ringan).

## 22.5 Arsitektur Caching

### Prinsip Dasar

Operasi terkait cache dihandle oleh **Service Layer**, bukan Handler atau Repository:

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                         LAYER ARSITEKTUR                                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │                      HANDLER LAYER                              │    │
│  │  - Menerima HTTP request                                        │    │
│  │  - Validasi input                                               │    │
│  │  - ❌ TIDAK akses cache langsung                                │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                              │                                          │
│                              ▼                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │                      SERVICE LAYER                              │    │
│  │  - Business logic                                               │    │
│  │  - ✅ BACA/TULIS cache (Cache-Aside Pattern)                    │    │
│  │  - ✅ Cache Invalidation logic                                  │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                              │                                          │
│                              ▼                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │                    REPOSITORY LAYER                             │    │
│  │  - Akses database (CRUD)                                        │    │
│  │  - ❌ TIDAK akses cache langsung                                │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### Cache-Aside Pattern

Cache-Aside adalah strategi caching paling umum. Aplikasi bertanggung jawab penuh mengelola cache:

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                    CACHE-ASIDE PATTERN                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  READ FLOW:                                                             │
│  ┌─────┐   1. Cek Cache    ┌─────────┐                                  │
│  │ App │ ─────────────────→ │  Cache  │                                 │
│  └─────┘                   └─────────┘                                  │
│     │                           │                                       │
│     │    2. Cache Miss          │                                       │
│     └───────────────────────────┘                                       │
│     │                                                                   │
│     ▼                                                                   │
│  ┌─────┐   3. Query DB      ┌─────────┐                                 │
│  │ App │ ─────────────────→ │ Database│                                 │
│  └─────┘                   └─────────┘                                  │
│     │                           │                                       │
│     │    4. Return Data         │                                       │
│     │    5. Store in Cache      │                                       │
│     └───────────────────────────┘                                       │
│                                                                         │
│  WRITE FLOW:                                                            │
│  ┌─────┐   1. Update DB     ┌─────────┐                                 │
│  │ App │ ─────────────────→ │ Database│                                 │
│  └─────┘                   └─────────┘                                  │
│     │                                                                   │
│     │    2. Invalidate/Delete Cache                                     │
│     └───────────────────────────┐                                       │
│                                 ▼                                       │
│                          ┌─────────┐                                    │
│                          │  Cache  │  ← ❌ Data dihapus/diinvalidate    │
│                          └─────────┘                                    │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

## 22.6 Implementasi Cache Client

### Interface Cache Client

```go
// pkg/cache/cache.go
package cache

import (
	"context"
	"time"
	"workshop/config"
)

type CacheClient interface {
    // Basic Function
	Ping(ctx context.Context) (string, error)
	Exists(ctx context.Context, key string) (bool, error)
	Get(ctx context.Context, key string) (string, error)
	Set(ctx context.Context, key string, value string) error
	SetWithExpiry(ctx context.Context, key string, value string, expiry time.Duration) error
	Del(ctx context.Context, keys []string) (int64, error)
	Close() error

    // Custome JSON
	GetJSON(ctx context.Context, key string, dest interface{}) error
	SetJSON(ctx context.Context, key string, value interface{}) error
	SetJSONWithExpiry(ctx context.Context, key string, value interface{}, expiry time.Duration) error

    // Atomic SET Function
	SAdd(ctx context.Context, key string, value string) (bool, error)
	SMembers(ctx context.Context, key string) ([]string, error)
	SCard(ctx context.Context, key string) (int64, error)
	SRem(ctx context.Context, key string, value ...string) error
	SScan(ctx context.Context, key string, cursor string, defaultBatchSize int) ([]string, string, error)
}

func NewCache(cfg config.CacheConfig) (CacheClient, error) {
	if cfg.ClusterMode {
		var cluster clusterCache
		return cluster.open(cfg)
	}

	var standalone standaloneCache
	return standalone.open(cfg)
}
```

### Implementasi Standalone Valkey

```go
// pkg/cache/valkey_standalone.go
package cache

import (
	"context"
	"encoding/json"
	"fmt"
	"time"
	wcfg "workshop/config"

	glide "github.com/valkey-io/valkey-glide/go/v2"
	"github.com/valkey-io/valkey-glide/go/v2/config"
	"github.com/valkey-io/valkey-glide/go/v2/models"
	"github.com/valkey-io/valkey-glide/go/v2/options"
)

type standaloneCache struct {
	client *glide.Client
}

func (s *standaloneCache) open(cfg wcfg.CacheConfig) (CacheClient, error) {
	clientConfig := config.NewClientConfiguration().
		WithAddress(&config.NodeAddress{
			Host: cfg.Host,
			Port: cfg.Port,
		}).
		WithRequestTimeout(cfg.DialTimeout)

	if cfg.Password != "" {
		var creds *config.ServerCredentials
		if cfg.Username != "" {
			creds = config.NewServerCredentials(cfg.Username, cfg.Password)
		} else {
			creds = config.NewServerCredentialsWithDefaultUsername(cfg.Password)
		}
		clientConfig = clientConfig.WithCredentials(creds)
	}

	client, err := glide.NewClient(clientConfig)
	if err != nil {
		return nil, fmt.Errorf("failed to create client: %w", err)
	}

	// Test koneksi
	ctx := context.Background()
	if _, err := client.Ping(ctx); err != nil {
		client.Close()
		return nil, fmt.Errorf("ping failed: %w", err)
	}

	return &standaloneCache{client: client}, nil
}

func (s *standaloneCache) Ping(ctx context.Context) (string, error) {
	return s.client.Ping(ctx)
}

func (s *standaloneCache) Exists(ctx context.Context, key string) (bool, error) {
	count, err := s.client.Exists(ctx, []string{key})
	if err != nil {
		return false, err
	}

	if count > 0 {
		return false, nil
	}

	return true, nil
}

func (s *standaloneCache) Get(ctx context.Context, key string) (string, error) {
	result, err := s.client.Get(ctx, key)
	if err != nil {
		return "", err
	}

	if result.IsNil() {
		return "", nil
	}

	return result.Value(), nil
}

func (s *standaloneCache) Set(ctx context.Context, key string, value string) error {
	_, err := s.client.Set(ctx, key, value)
	return err
}

func (s *standaloneCache) SetWithExpiry(ctx context.Context, key string, value string, expiry time.Duration) error {
	_, err := s.client.SetWithOptions(ctx, key, value, options.SetOptions{
		Expiry: options.NewExpiryIn(expiry),
	})
	return err
}

func (s *standaloneCache) Del(ctx context.Context, keys []string) (int64, error) {
	return s.client.Del(ctx, keys)
}

func (s *standaloneCache) Close() error {
	s.client.Close()
	return nil
}

func (s *standaloneCache) GetJSON(ctx context.Context, key string, dest interface{}) error {
	val, err := s.Get(ctx, key)
	if err != nil {
		return fmt.Errorf("failed to get key %s: %w", key, err)
	}

	if err := json.Unmarshal([]byte(val), dest); err != nil {
		return fmt.Errorf("failed to unmarshal JSON for key %s: %w", key, err)
	}

	return nil
}

func (s *standaloneCache) SetJSON(ctx context.Context, key string, value interface{}) error {
	data, err := json.Marshal(value)
	if err != nil {
		return fmt.Errorf("failed to marshal JSON for key %s: %w", key, err)
	}

	if err := s.Set(ctx, key, string(data)); err != nil {
		return fmt.Errorf("failed to set key %s: %w", key, err)
	}

	return nil
}

func (s *standaloneCache) SetJSONWithExpiry(ctx context.Context, key string, value interface{}, expiry time.Duration) error {
	data, err := json.Marshal(value)
	if err != nil {
		return fmt.Errorf("failed to marshal JSON for key %s: %w", key, err)
	}

	if err := s.SetWithExpiry(ctx, key, string(data), expiry); err != nil {
		return fmt.Errorf("failed to set key %s: %w", key, err)
	}

	return nil
}

func (s *standaloneCache) SAdd(ctx context.Context, key, value string) (bool, error) {
	count, err := s.client.SAdd(ctx, key, []string{value})
	if err != nil {
		return false, err
	}

	if count == 0 {
		return false, nil
	}
	return true, nil
}

func (s *standaloneCache) SMembers(ctx context.Context, key string) ([]string, error) {
	mapStr, err := s.client.SMembers(ctx, key)
	if err != nil {
		return nil, err
	}

	members := make([]string, 0, len(mapStr))
	for member := range mapStr {
		members = append(members, member)
	}
	return members, nil
}

func (s *standaloneCache) SCard(ctx context.Context, key string) (int64, error) {
	return s.client.SCard(ctx, key)
}

func (s *standaloneCache) SRem(ctx context.Context, key string, value ...string) error {
	_, err := s.client.SRem(ctx, key, value)
	return err
}

func (s *standaloneCache) SScan(ctx context.Context, key string, cursor string, defaultBatchSize int) ([]string, string, error) {
	cursorModel := models.NewCursorFromString(cursor)
	result, err := s.client.SScan(ctx, key, cursorModel)
	if err != nil {
		return nil, "", fmt.Errorf("failed to SScan key %s: %w", key, err)
	}
	return result.Data, result.Cursor.String(), nil
}
```

### Konfigurasi Cache

```go
// config/config.go
type CacheConfig struct {
    Host        string
    Port        string
    Password    string
    Username    string
    ClusterMode bool
    DialTimeout time.Duration
}

type TTLConfig struct {
    TTLDefault time.Duration
    TTLShort   time.Duration
    TTLLong    time.Duration
}
```

## 22.7 Helper List Cache (Key Registering)

Untuk cache dengan banyak parameter (seperti List Users dengan sorting, filtering, pagination), kita perlu strategi khusus untuk mengelola key.
Masalah

Pada List Users, setiap kombinasi parameter menghasilkan key berbeda:
- `users::list::order:name::sort:asc::search:admin::limit:10::page:1`
- `users::list::order:name::sort:desc::search:admin::limit:10::page:1`
- `users::list::order:email::sort:asc::search:john::limit:20::page:2`

**Masalah pada Write Flow:** Bagaimana menghapus semua key yang relevan saat data berubah?

Pendekatan yang umum digunakan ada 3:
1. **Cache dengan TTL pendek**, sehingga tidak perlu dipusingkan bagaimana melakukan invalidate, karena cache secara otomatis expired ketika TTL habis. Kelemahannya adalah data cache tidak bisa dijamin merupakan data paling baru.
2. **Versioning Number**. Dibuat cache untuk mengelola versioning dari setiap kelompok cache. Keuntungannya data cache selalu paling update, mudah diterapkan, dan tidak perlu dipusingkan dengan invalidate cache. Kelemahanya storage membengkak karena cache yang sudah tidak valid masih disimpan hingga TTL nya habis.
3. **Key Registering**, dimana setiap key didaftarkan ke dalam index_list, sehingga key yang sudah tidak terpakai bisa dihapus. Keuntungan data cache tetap terbaru, storage tidak membengkak, namun kompleksitasnya tinggi.  

### Solusi: Key Registering dengan SET

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                    KEY REGISTERING PATTERN                              │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  READ FLOW:                                                             │
│  1. Generate cache key                                                  │
│  2. Register key ke index (SADD)                                        │
│  3. Store data di cache                                                 │
│                                                                         │
│  WRITE FLOW:                                                            │
│  1. Get semua key dari index (SMEMBERS)                                 │
│  2. Delete semua key (DEL)                                              │
│  3. Delete index (DEL)                                                  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### Helper List Cache

Buat helper listcache dengan fungsi :
- GenereateKey untuk konsistensi penamaan key cache
- AddKeyToIndex untuk proses registering key
- InvalidateListCache untuk proses invalidate cache
- Cleanup untuk menghapus member key yang sudah expired

Buat file `pkg/listcache/helper.go`

```go
package listcache

import (
	"context"
	"fmt"
	"log/slog"
	"time"
	"workshop/pkg/cache"

	"github.com/jacky-htg/go-libs/logger"
)

const (
	defaultBatchSize = 100 // Jumlah key yang diproses per siklus SSCAN
)

func GenerateListCacheKey(prefixKey, order, sort, search string, limit, page int) string {
	return fmt.Sprintf("%sorder:%s::sort:%s::search:%s::limit:%d::page:%d",
		prefixKey, order, sort, search, limit, page)
}

func InvalidateListCache(ctx context.Context, log logger.Logger, client cache.CacheClient, indexKey string) error {
	// 1. Get semua key dari index
	keys, err := client.SMembers(ctx, indexKey)
	if err != nil {
		return err
	}

	if len(keys) == 0 {
		return nil
	}

	// 2. Delete semua key (batch)
	batchSize := 100
	for i := 0; i < len(keys); i += batchSize {
		end := i + batchSize
		if end > len(keys) {
			end = len(keys)
		}

		batch := keys[i:end]
		if _, err := client.Del(ctx, batch); err != nil {
			log.Error(ctx, "failed to delete cache batch",
				slog.Any("error", err),
				slog.Int("batch", i/batchSize))
		}
	}

	// 3. Hapus index (reset)
	if _, err := client.Del(ctx, []string{indexKey}); err != nil {
		log.Error(ctx, "failed to delete index", slog.Any("error", err))
	}

	log.Info(ctx, "invalidated list cache", slog.Int("count", len(keys)))
	return nil
}

func AddKeyToIndex(ctx context.Context, log logger.Logger, client cache.CacheClient, cacheKey, indexKey string, maxIndexSize int) error {
	added, err := client.SAdd(ctx, indexKey, cacheKey)
	if err != nil {
		return err
	}

	if !added {
		return nil
	}

	size, err := client.SCard(ctx, indexKey)
	if err != nil {
		return err
	}

	// Jika index terlalu besar, hapus yang paling tua
	if size > int64(maxIndexSize) {
		cleanupStaleIndexBatch(ctx, log, client, indexKey)
	}

	return nil
}

func Cleanup(ctx context.Context, log logger.Logger, client cache.CacheClient, indexKey string) {
	cleanupStaleIndexBatch(ctx, log, client, indexKey)
}

func cleanupStaleIndexBatch(ctx context.Context, log logger.Logger, client cache.CacheClient, indexKey string) {
	startTime := time.Now()
	totalRemoved := 0
	totalChecked := 0
	cursor := "0" // SSCAN menggunakan string cursor

	log.Debug(ctx, "starting cleanup batch", slog.String("index_key", indexKey))

	for {
		// 1. Gunakan SSCAN untuk iterasi bertahap
		keys, nextCursor, err := client.SScan(ctx, indexKey, cursor, defaultBatchSize)
		if err != nil {
			log.Error(ctx, "failed to scan index",
				slog.String("index_key", indexKey),
				slog.Any("error", err))
			return
		}

		totalChecked += len(keys)

		// 2. Proses keys dalam batch kecil
		if len(keys) > 0 {
			staleKeys := make([]string, 0)

			for _, key := range keys {
				exists, err := client.Exists(ctx, key)
				if err != nil {
					log.Warn(ctx, "failed to check existence",
						slog.String("key", key),
						slog.Any("error", err))
					continue
				}

				if !exists {
					staleKeys = append(staleKeys, key)
				}
			}

			// 3. Hapus stale keys dari index (batch kecil)
			if len(staleKeys) > 0 {
				// Hapus dalam batch kecil (10 keys per batch)
				for i := 0; i < len(staleKeys); i += 10 {
					end := i + 10
					if end > len(staleKeys) {
						end = len(staleKeys)
					}
					err = client.SRem(ctx, indexKey, staleKeys[i:end]...)
					if err != nil {
						log.Debug(ctx, "error srem", slog.Any("error", err))
					}
				}
				totalRemoved += len(staleKeys)
			}

			// 4. Log progress setiap 50 keys
			if totalChecked%50 == 0 && totalChecked > 0 {
				log.Debug(ctx, "cleanup progress",
					slog.Int("checked", totalChecked),
					slog.Int("removed", totalRemoved))
			}
		}

		// 5. Cek cursor - jika "0" berarti selesai
		if nextCursor == "0" {
			break
		}
		cursor = nextCursor
	}

	// 6. Jika semua stale sudah dibersihkan dan index kosong, hapus index key
	if totalChecked > 0 && totalRemoved > 0 {
		remaining, err := client.SCard(ctx, indexKey)
		if err == nil && remaining == 0 {
			client.Del(ctx, []string{indexKey})
			log.Info(ctx, "index emptied, removed key",
				slog.String("index_key", indexKey))
		}
	}

	elapsed := time.Since(startTime)
	if totalChecked > 0 {
		log.Info(ctx, "cleanup batch completed",
			slog.Int("checked", totalChecked),
			slog.Int("removed", totalRemoved),
			slog.Duration("duration", elapsed))
	}
}
```

## 22.8 Implementasi Cache di Service User

```go
package service

import (
	"context"
	"database/sql"
	"fmt"
	"log/slog"
	"workshop/config"
	"workshop/internal/model"
	"workshop/internal/repository"
	"workshop/pkg/cache"
	"workshop/pkg/errors"
	"workshop/pkg/listcache"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

const (
	usersListPrefix = "users::list::"
	usersIndexKey   = "users::list::index"
	maxIndexSize    = 1000 // Batasi jumlah key di index
)

type Users interface {
	List(ctx context.Context, search, order, sort string, limit, page int) ([]model.User, model.Pagination, *errors.BusinessError)
	Create(ctx context.Context, user *model.User) *errors.BusinessError
	FindByID(ctx context.Context, id string) (*model.User, *errors.BusinessError)
	Update(ctx context.Context, user *model.User) *errors.BusinessError
	Delete(ctx context.Context, id string) *errors.BusinessError
}

type users struct {
	db    *sql.DB
	cache cache.CacheClient
	log   logger.Logger
	ttl   config.TTLConfig
	repo  repository.UserRepository
}

func NewUsers(db *sql.DB, cache cache.CacheClient, log logger.Logger, ttl config.TTLConfig, repo repository.UserRepository) Users {
	return &users{db: db, cache: cache, log: log, ttl: ttl, repo: repo}
}

func (u *users) List(ctx context.Context, search, order, sort string, limit, page int) ([]model.User, model.Pagination, *errors.BusinessError) {
	cacheKey := listcache.GenerateListCacheKey(usersListPrefix, order, sort, search, limit, page)

	var cachedResult struct {
		Users      []model.User     `json:"users"`
		Pagination model.Pagination `json:"pagination"`
	}

	err := u.cache.GetJSON(ctx, cacheKey, &cachedResult)
	if err == nil {
		return cachedResult.Users, cachedResult.Pagination, nil
	}

	pagination := model.Pagination{Page: page, Limit: limit}
	offset := (pagination.Page - 1) * pagination.Limit

	users, count, err := u.repo.List(ctx, search, order, sort, pagination.Limit, offset)
	if err != nil {
		return nil, pagination, errors.InternalServerErrorWrap(err, "error listing users")
	}
	pagination.Count = count

	if err := u.saveListCache(ctx, cacheKey, users, pagination); err != nil {
		u.log.Warn(ctx, "failed to save cache", slog.Any("error", err))
	}

	return users, pagination, nil
}

func (u *users) Create(ctx context.Context, user *model.User) *errors.BusinessError {
	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(ctx, "error generate password", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err, "error generating password")
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	tx, err := u.db.BeginTx(ctx, nil)
	if err != nil {
		u.log.Error(ctx, "error begin tx", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}
	defer tx.Rollback()

	if err := u.repo.Create(ctx, tx, user); err != nil {
		return errors.InternalServerErrorWrap(err, "error creating user")
	}

	for _, v := range user.Roles {
		if err := u.repo.AssignRole(ctx, tx, user.ID, int64(v.ID)); err != nil {
			return errors.InternalServerErrorWrap(err, "error assign role")
		}
	}

	if err := listcache.InvalidateListCache(ctx, u.log, u.cache, usersIndexKey); err != nil {
		return errors.InternalServerErrorWrap(err, "error invalidate list cache")
	}

	if err = tx.Commit(); err != nil {
		return errors.InternalServerErrorWrap(err)
	}

	return nil
}

func (u *users) FindByID(ctx context.Context, id string) (*model.User, *errors.BusinessError) {
	cacheKey := "users::" + id
	var user *model.User
	if err := u.cache.GetJSON(ctx, cacheKey, &user); err == nil {
		return user, nil
	}

	user, err := u.repo.FindByID(ctx, id)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error finding user")
	}
	if user == nil {
		return nil, errors.NotFound("user not found")
	}

	if err := u.cache.SetJSONWithExpiry(ctx, cacheKey, user, u.ttl.TTLDefault); err != nil {
		u.log.Warn(ctx, "set cache failed ", slog.Any("error", err))
	}

	return user, nil
}

func (u *users) Update(ctx context.Context, user *model.User) *errors.BusinessError {
	cacheKey := "users::" + user.ID

	existUser, err := u.repo.FindByID(ctx, user.ID)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding user")
	}
	if existUser == nil {
		return errors.NotFound("user not found")
	}

	tx, err := u.db.BeginTx(ctx, nil)
	if err != nil {
		u.log.Error(ctx, "error begin tx", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}
	defer tx.Rollback()

	err = u.repo.Update(ctx, tx, user)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error updating user")
	}

	mapExistingRoles := make(map[int]model.Role)
	mapNewRoles := make(map[int]model.Role)

	for _, v := range existUser.Roles {
		mapExistingRoles[v.ID] = v
	}

	for _, w := range user.Roles {
		if _, ok := mapExistingRoles[w.ID]; ok {
			delete(mapExistingRoles, w.ID)
		} else {
			mapNewRoles[w.ID] = w
		}
	}

	for _, val := range mapNewRoles {
		if err := u.repo.AssignRole(ctx, tx, user.ID, int64(val.ID)); err != nil {
			return errors.InternalServerErrorWrap(err, "error update assign role")
		}
	}

	for _, val := range mapExistingRoles {
		if err := u.repo.RemoveRole(ctx, tx, user.ID, int64(val.ID)); err != nil {
			return errors.InternalServerErrorWrap(err, "error update assign role")
		}
	}

	if _, err := u.cache.Del(ctx, []string{cacheKey}); err != nil {
		u.log.Error(ctx, "del cache failed", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}

	if err = tx.Commit(); err != nil {
		return errors.InternalServerErrorWrap(err)
	}

	return nil
}

func (u *users) Delete(ctx context.Context, id string) *errors.BusinessError {
	cacheKey := "users::" + id
	existUser, err := u.repo.FindByID(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding user")
	}
	if existUser == nil {
		return errors.NotFound("user not found")
	}

	if _, err := u.cache.Del(ctx, []string{cacheKey}); err != nil {
		u.log.Error(ctx, "del cache failed", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}

	if err := listcache.InvalidateListCache(ctx, u.log, u.cache, usersIndexKey); err != nil {
		u.log.Error(ctx, "del list cache failed", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}

	err = u.repo.Delete(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error deleting user")
	}
	return nil
}

func (u *users) saveListCache(ctx context.Context, cacheKey string, users []model.User, pagination model.Pagination) error {
	cacheData := struct {
		Users      []model.User     `json:"users"`
		Pagination model.Pagination `json:"pagination"`
	}{
		Users:      users,
		Pagination: pagination,
	}

	if err := listcache.AddKeyToIndex(ctx, u.log, u.cache, cacheKey, usersIndexKey, maxIndexSize); err != nil {
		return fmt.Errorf("failed to update index: %w", err)
	}

	if err := u.cache.SetJSONWithExpiry(ctx, cacheKey, cacheData, u.ttl.TTLDefault); err != nil {
		u.log.Warn(ctx, "data cache failed, index will be cleaned up",
			slog.String("key", cacheKey),
			slog.Any("error", err))

		u.cache.SRem(ctx, usersIndexKey, cacheKey)
		return fmt.Errorf("failed to set data: %w", err)
	}

	return nil
}
```

## 22.9 Mengatasi Masalah Cache

### Cache Stampede

**Masalah:** Cache expired bersamaan → banyak request hit database simultaneously.

**Solusi:** Tambahkan jitter pada TTL:

```go
func (u *users) saveListCache(ctx context.Context, cacheKey string, users []model.User, pagination model.Pagination) error {
    // Base TTL + random jitter (±10%)
    baseTTL := u.ttl.TTLDefault
    jitter := time.Duration(rand.Intn(int(baseTTL/5))) - baseTTL/10
    finalTTL := baseTTL + jitter
    
    return u.cache.SetJSONWithExpiry(ctx, cacheKey, cacheData, finalTTL)
}
```

### Thundering Herd

**Masalah:** Banyak request konkuren untuk data yang sama → semua request database.

**Solusi:** Gunakan Single Flight Pattern (hanya 1 request ke database, sisanya menunggu):

```goi
import "golang.org/x/sync/singleflight"

var sf singleflight.Group

func (u *users) FindByID(ctx context.Context, id string) (*model.User, *errors.BusinessError) {
    cacheKey := "users::" + id
    
    // Coba cache
    var user *model.User
    if err := u.cache.GetJSON(ctx, cacheKey, &user); err == nil {
        return user, nil
    }

    // Single flight: hanya 1 request yang query DB
    result, err, _ := sf.Do(cacheKey, func() (interface{}, error) {
        // Double-check cache
        if err := u.cache.GetJSON(ctx, cacheKey, &user); err == nil {
            return user, nil
        }
        
        user, err := u.repo.FindByID(ctx, id)
        if err != nil {
            return nil, err
        }
        
        // Simpan cache
        u.cache.SetJSONWithExpiry(ctx, cacheKey, user, u.ttl.TTLDefault)
        return user, nil
    })
    
    if err != nil {
        return nil, errors.InternalServerErrorWrap(err, "error finding user")
    }
    return result.(*model.User), nil
}
```

Lebih detail terkait  pattern single flight bisa dibaca di [Single Flight Pattern](https://golang-microservices.rijalasepnugroho.com/design-pattern/03-concurrency-pattern/12-single-flight).

### Operasi Penghapusan Data yang Besar

**Masalah:** SMEMBERS + DEL untuk ribuan key bersifat blocking.

**Solusi:** Gunakan SSCAN untuk iterasi bertahap (sudah diimplementasikan di cleanupStaleIndexBatch).

### Operasi Blocking di Valkey Glide

**Masalah:** Valkey Glide menggunakan multiplex yang unggul secara throughput namun lemah terhadap operasi blcoking seperti `BLPOP` atau penghapusan data yang besar.

**Solusi:** 
- On premise : gunakan valkey-go, dimana koneksi valkey otomatis dijalankan dengan multiplex untuk perintah nonblocking, namun perintah blocking akan dijalankan dengan koneksi pool connection.
- Cloud AWS/GCP : buat dua koneksi client. Pilihan koneksi diset secara manual di dalam kode agar operasi blocking tidak menghambat operasi nonblocking. Jika operasi nonbliocking gunakan koneksi A, semantara operasi blocking menggunakan koneksi B.

## 22.10 Update Bootstrap dengan Cache

```go
// internal/bootstrap/app.go
func NewApp() (App, error) {
    cfg, err := config.LoadConfig()
    if err != nil {
        return App{}, fmt.Errorf("loading config: %w", err)
    }

    db, err := database.OpenDB(cfg)
    if err != nil {
        return App{}, fmt.Errorf("opening database: %w", err)
    }

    log := logger.InitLogger(nil)
    validate := validator.New()

    // Inisialisasi cache
    cacheClient, err := cache.NewCache(cfg.Cache)
    if err != nil {
        return App{}, fmt.Errorf("creating cache client: %w", err)
    }

    return App{
        Config:   cfg,
        Database: db,
        Log:      log,
        Validate: validate,
        Cache:    cacheClient,
        Cleanup: func() {
            cacheClient.Close()
            db.Close()
        },
    }, nil
}
```

## Ringkasan Bab 22

Di bab ini kita telah belajar:

| Komponen | Fungsi |
|----------|--------|
| Cache Client Interface | Abstraksi untuk berbagai implementasi cache |
| Valkey Standalone | Implementasi dengan valkey-glide |
| Cache-Aside Pattern | Strategi caching dengan invalidation manual |
| Key Registering | Mengelola cache dengan banyak parameter menggunakan SET |
| List Cache Helper | Generate key, add to index, invalidate, cleanup |
| Single Flight | Mencegah Thundering Herd |

Manfaat yang kita peroleh:
- ✅ Response time turun dari 100ms ke 1-5ms
- ✅ Beban database berkurang 90%+
- ✅ Cache terkelola dengan baik (invalidation otomatis)
- ✅ Index management dengan SET (SADD, SMEMBERS, SSCAN, SREM)
- ✅ Proteksi dari Cache Stampede dan Thundering Herd

Yang akan datang:
- Saat ini kita belum pernah memanggil API eksternal
- Bab selanjutnya: Call API Third Party – memanggil service eksternal dengan retry, timeout, dan circuit breaker