# Bab 21: API Testing

API testing (atau integration testing) adalah pengujian untuk memastikan API yang dibangun sesuai dengan kontrak yang telah ditetapkan. Berbeda dengan unit testing yang menguji komponen secara terisolasi, API testing menggunakan **server dan database sungguhan** (bukan mock) untuk memverifikasi perilaku end-to-end.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/21-apit-testing](https://github.com/jacky-htg/workshop/tree/main/21-api-testing)

## 21.1 Unit Testing vs API Testing vs E2E Testing

| Aspek | Unit Testing | API Testing | E2E Testing |
|-------|--------------|-------------|-------------|
| Fokus | Coverage & logika percabangan | Kontrak API & behavior | Flow satu journey (user story) |
| Database | Mock | Sungguhan (container) | Sungguhan (container) |
| Server | Tidak perlu | httptest | httptest |
| Cakupan | 1 fungsi/method | 1 endpoint | Multiple endpoint dalam satu skenario |
| Kecepatan | Sangat cepat | Cepat | Sedang |
| Biaya maintenance | Rendah | Sedang | Tinggi |

## 21.2 Struktur Folder Testing

Kita akan mengelompokkan test berdasarkan jenisnya:

```text
project/
├── internal/
│   ├── handler/
│   │   ├── role.go
│   │   └── role_test.go           ✅ Unit test (package yang sama)
│   ├── service/
│   │   ├── role.go
│   │   └── role_test.go           ✅ Unit test (package yang sama)
│   └── repository/
│       ├── role.go
│       └── role_test.go           ✅ Unit test (package yang sama)
│
└── test/                          ✅ API/E2E tests (folder terpisah)
    ├── api/
    │   ├── auth/
    │   │   ├── main_test.go
    │   │   └── login_test.go
    │   └── role/
    │       ├── main_test.go
    │       └── create_test.go
    └── e2e/
        ├── helper/
        │   ├── base_helper.go
        │   ├── auth_helper.go
        │   ├── role_helper.go
        │   ├── user_helper.go
        │   └── access_helper.go
        └── rbac/
            ├── main_test.go
            └── rbac_test.go
```

## 21.3 Database Container dengan Testcontainers

API testing membutuhkan database sungguhan. Ada dua pendekatan:

| Pendekatan | Kelebihan | Kekurangan |
|------------|-----------|------------|
| Database dedicated | Cepat, stabil | Race condition antar test, data bersinggungan |
| Database on-the-fly | Isolated, clean state | Lebih lambat (start container) |

Pendekatan database on-the-fly membuat container database saat test dimulai dan menghapusnya setelah selesai. Hal ini bisa dilakukan dengan memanfaatkan perintah `exec.Command` yang bisa digunakan untuk menjalankan perintah `docker run` maupun `docker container rm -f`. 

```go
// StartContainer runs a posgres container to execute commands.
func StartContainer(t *testing.T) {
    t.Helper()

    cmd := exec.Command("docker", "run", "-d", "--name", "postgres_test", "--publish", "54320:5432", "--env", "POSTGRES_PASSWORD=1234", "postgres:16-alpine")
    var out bytes.Buffer
    cmd.Stdout = &out
    if err := cmd.Run(); err != nil {
        t.Fatalf("could not start docker : %v", err)
    }

}

// StopContainer stops and removes the specified container.
func StopContainer(t *testing.T) {
    t.Helper()

    if err := exec.Command("docker", "container", "rm", "-f", "postgres_test").Run(); err != nil {
        t.Fatalf("could not stop mysql container: %v", err)
    }
}
```

Pendekatan native `exec.Command()` mempunyai keunggulan di performance, namun untuk alasan kemudahan dalam maintenance, Kita akan menggunakan pendekatan on-the-fly dengan library `testcontainers-go`.

### PostgreSQL Container

```go
// test/containers/postgres.go
package containers

import (
	"context"
	"database/sql"
	"fmt"
	"time"

	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
	"github.com/testcontainers/testcontainers-go"
	"github.com/testcontainers/testcontainers-go/wait"
)

type PostgreSQLContainer struct {
	Container testcontainers.Container
	DB        *sql.DB
	Host      string
	Port      string
}

func NewPostgreSQLContainer(ctx context.Context) (*PostgreSQLContainer, error) {
	req := testcontainers.ContainerRequest{
		Image:        "postgres:16-alpine",
		ExposedPorts: []string{"5432/tcp"},
		Env: map[string]string{
			"POSTGRES_USER":     "testuser",
			"POSTGRES_PASSWORD": "testpass",
			"POSTGRES_DB":       "testdb",
		},
		WaitingFor: wait.ForLog("database system is ready to accept connections").
			WithOccurrence(2).
			WithStartupTimeout(60 * time.Second),
	}

	container, err := testcontainers.GenericContainer(ctx, testcontainers.GenericContainerRequest{
		ContainerRequest: req,
		Started:          true,
	})
	if err != nil {
		return nil, fmt.Errorf("failed to start postgres container: %w", err)
	}

	host, err := container.Host(ctx)
	if err != nil {
		return nil, err
	}

	port, err := container.MappedPort(ctx, "5432")
	if err != nil {
		return nil, err
	}

	connStr := fmt.Sprintf("host=%s port=%s user=testuser password=testpass dbname=testdb sslmode=disable",
		host, port.Port())

	db, err := sql.Open("postgres", connStr)
	if err != nil {
		return nil, err
	}

	// Ping database
	if err := db.Ping(); err != nil {
		return nil, err
	}

	return &PostgreSQLContainer{
		Container: container,
		DB:        db,
		Host:      host,
		Port:      port.Port(),
	}, nil
}

func (p *PostgreSQLContainer) Close() error {
	if p.DB != nil {
		p.DB.Close()
	}
	return p.Container.Terminate(context.Background())
}

func (p *PostgreSQLContainer) RunMigrations(migrationsPath string) error {
	return migration.Migrate(p.DB, migrationsPath)
}
```

### Container Registry (Singleton)

Untuk mengelola banyak container (PostgreSQL, Redis, dll), buat registry dengan pattern singleton:

```go
// test/containers/registry.go
package containers

import (
	"context"
	"sync"
)

type ContainerRegistry struct {
	mu       sync.RWMutex
	postgres *PostgreSQLContainer
}

var (
	registry *ContainerRegistry
	once     sync.Once
)

func GetRegistry() *ContainerRegistry {
	once.Do(func() {
		registry = &ContainerRegistry{}
	})
	return registry
}

func (r *ContainerRegistry) StartPostgres(ctx context.Context) (*PostgreSQLContainer, error) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.postgres == nil {
		pg, err := NewPostgreSQLContainer(ctx)
		if err != nil {
			return nil, err
		}
		r.postgres = pg
	}
	return r.postgres, nil
}

func (r *ContainerRegistry) CloseAll() {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.postgres != nil {
		r.postgres.Close()
	}
}
```

## 21.4 Setup TestMain

`TestMain` adalah fungsi khusus di Go yang dijalankan sekali sebelum semua test dalam package dieksekusi. Kita gunakan untuk:
1. Start container database
2. Jalankan migration
3. Scan access routes
4. Start HTTP test server
5. Cleanup setelah semua test selesai

```go
// test/setup/setup.go
package setup

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"
	"workshop/config"
	"workshop/internal/dto"
	"workshop/internal/repository"
	"workshop/internal/router"
	"workshop/internal/service"
	"workshop/test/containers"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
	"github.com/stretchr/testify/require"
)

var (
	testServer *httptest.Server
	once       sync.Once
	initErr    error
)

// InitServer inisialisasi server sekali untuk semua test
func InitServer() error {
	once.Do(func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()

		registry := containers.GetRegistry()

		pg, err := registry.StartPostgres(ctx)
		if err != nil {
			initErr = err
			return
		}

		if err := pg.RunMigrations("../../../migration"); err != nil {
			initErr = err
			return
		}

		cfg := config.Config{
			Server: config.ServerConfig{GatewayTimeout: 30 * time.Second},
			Token:  config.TokenConfig{TokenSalt: "test-secret-key", TokenExp: 1},
		}

		log := logger.InitLogger(nil)
		validate := validator.New()

		repo := repository.NewAccessRepository(pg.DB, log)
		accessSvc := service.NewAccesses(pg.DB, log, repo)
		if err := accessSvc.ScanAccess(context.Background(), "../../data/route.go"); err != nil {
			initErr = err
			return
		}

		router := router.Api(cfg, pg.DB, log, validate)
		testServer = httptest.NewServer(router)
	})
	return initErr
}

// CloseServer cleanup
func CloseServer() {
	if testServer != nil {
		testServer.Close()
	}
	registry := containers.GetRegistry()
	registry.CloseAll()
}

// GetServerURL returns test server URL
func GetServerURL() string {
	return testServer.URL
}
```

### Data Pendukung

Untuk data accesses routing, saya membuat data test `test/data/router.go`:

```go
package data_test

import (
	"fmt"
	"workshop/pkg/app"
)

func Api() {
	routes := []app.RouteDefinition{
		{Method: "GET", Path: "/accesses", Group: "accesses", Alias: "accesses::list", HandlerFunc: nil},

		{Method: "GET", Path: "/roles", Group: "roles", Alias: "roles::list", HandlerFunc: nil},
		{Method: "POST", Path: "/roles", Group: "roles", Alias: "roles::create", HandlerFunc: nil},
		{Method: "GET", Path: "/roles/{id}", Group: "roles", Alias: "roles::view", HandlerFunc: nil},
		{Method: "PUT", Path: "/roles/{id}", Group: "roles", Alias: "roles::update", HandlerFunc: nil},
		{Method: "DELETE", Path: "/roles/{id}", Group: "roles", Alias: "roles::delete", HandlerFunc: nil},
		{Method: "POST", Path: "/roles/{id}/access/{access_id}", Group: "roles", Alias: "roles::grant", HandlerFunc: nil},
		{Method: "DELETE", Path: "/roles/{id}/access/{access_id}", Group: "roles", Alias: "roles::revoke", HandlerFunc: nil},

		{Method: "GET", Path: "/users", Group: "users", Alias: "users::list", HandlerFunc: nil},
		{Method: "POST", Path: "/users", Group: "users", Alias: "users::create", HandlerFunc: nil},
		{Method: "GET", Path: "/users/{id}", Group: "users", Alias: "users::view", HandlerFunc: nil},
		{Method: "PUT", Path: "/users/{id}", Group: "users", Alias: "users::update", HandlerFunc: nil},
		{Method: "DELETE", Path: "/users/{id}", Group: "users", Alias: "users::delete", HandlerFunc: nil},
	}

	fmt.Println(routes)
}
```

### TestMain di Setiap Package Test

Setiap package test memiliki TestMain sendiri, misalnya di paket `auth`, buat file `test/api/auth/main_test.go`

```go
//go:build integration

package auth_test

import (
	"os"
	"testing"
	"workshop/test/setup"
)

func TestMain(m *testing.M) {
	if err := setup.InitServer(); err != nil {
		panic(err)
	}
	defer setup.CloseServer()
	code := m.Run()
	os.Exit(code)
}
```

## 21.5 Helper untuk API Call

Buat helper untuk memanggil API dengan mudah. Update file `test/setup/setup.go` untuk menambahkan helper ini :

```go


func CallAPI(t *testing.T, method, path, token string, body interface{}) *http.Response {
	t.Helper()

	var reqBody *bytes.Buffer = &bytes.Buffer{}
	if body != nil {
		jsonBody, err := json.Marshal(body)
		require.NoError(t, err)
		reqBody = bytes.NewBuffer(jsonBody)
	}

	req, err := http.NewRequest(method, GetServerURL()+path, reqBody)
	require.NoError(t, err)

	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}

	client := &http.Client{}
	resp, err := client.Do(req)
	require.NoError(t, err)

	return resp
}

func GetToken(t *testing.T, username, password string) string {
	bodyReq := dto.LoginRequest{
		Username: username,
		Password: password,
	}

	w := CallAPI(t, "POST", "/login", "", bodyReq)
	defer w.Body.Close()

	var resp struct {
		Data struct {
			Token string `json:"token"`
		} `json:"data"`
	}

	err := json.NewDecoder(w.Body).Decode(&resp)
	require.NoError(t, err)

	return resp.Data.Token
}
```

## 21.6 Auth API Test

### Login Scenarios

Buat file `test/api/auth/login_test.go`, pastikan seluruh skenario yang ada di api kontrak tercover dalam api testing.

```go
//go:build integration

package auth_test

import (
	"encoding/json"
	"net/http"
	"testing"
	"workshop/internal/dto"
	"workshop/pkg/errors"
	"workshop/test/setup"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

type LoginScenario struct {
	Name                 string
	Request              dto.LoginRequest
	ExpectHttpStatusCode int
	ExpectBusinessCode   string
	ExpectMessage        string
	ValidateResponse     func(t *testing.T, data json.RawMessage)
}

var loginScenarios = []LoginScenario{
	{
		Name:                 "success login - admin",
		Request:              dto.LoginRequest{Username: "admin@example.com", Password: "1234"},
		ExpectHttpStatusCode: http.StatusOK,
		ExpectBusinessCode:   "B1",
		ExpectMessage:        "Success",
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var loginData dto.LoginResponse
			err := json.Unmarshal(data, &loginData)
			require.NoError(t, err)

			assert.NotEmpty(t, loginData.Token)
			assert.Equal(t, "019eb960-a27d-73c8-9703-b23a9f50dc83", loginData.User.ID)
			assert.Equal(t, "Admin", loginData.User.Name)
			assert.Contains(t, loginData.Accesses, "root")
		},
	},
	{
		Name:                 "invalid input - username required",
		Request:              dto.LoginRequest{Username: "", Password: "1234"},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        errors.InvalidInputMessage,
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var validateData map[string]string
			err := json.Unmarshal(data, &validateData)
			require.NoError(t, err)
			assert.Contains(t, validateData["username"], "Username is required")
		},
	},
	{
		Name:                 "invalid input - username not email",
		Request:              dto.LoginRequest{Username: "admin", Password: "1234"},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        errors.InvalidInputMessage,
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var validateData map[string]string
			err := json.Unmarshal(data, &validateData)
			require.NoError(t, err)
			assert.Contains(t, validateData["username"], "Username must be a valid email address")
		},
	},
	{
		Name:                 "invalid input - password required",
		Request:              dto.LoginRequest{Username: "admin@example.com", Password: ""},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        errors.InvalidInputMessage,
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var validateData map[string]string
			err := json.Unmarshal(data, &validateData)
			require.NoError(t, err)
			assert.Contains(t, validateData["password"], "Password is required")
		},
	},
	{
		Name:                 "wrong password",
		Request:              dto.LoginRequest{Username: "admin@example.com", Password: "4321"},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        "Invalid username/password",
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			assert.Equal(t, "{}", string(data))
		},
	},
	{
		Name:                 "user not found",
		Request:              dto.LoginRequest{Username: "notfound@example.com", Password: "1234"},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        "Invalid username/password",
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			assert.Equal(t, "{}", string(data))
		},
	},
}

func TestAuth_Login(t *testing.T) {
	for _, sc := range loginScenarios {
		t.Run(sc.Name, func(t *testing.T) {
			w := setup.CallAPI(t, "POST", "/login", "", sc.Request)
			defer w.Body.Close()

			assert.Equal(t, sc.ExpectHttpStatusCode, w.StatusCode)

			var resp struct {
				Status  string          `json:"status"`
				Message string          `json:"message"`
				Data    json.RawMessage `json:"data"`
			}
			err := json.NewDecoder(w.Body).Decode(&resp)
			require.NoError(t, err)

			assert.Equal(t, sc.ExpectBusinessCode, resp.Status)
			assert.Equal(t, sc.ExpectMessage, resp.Message)

			if sc.ValidateResponse != nil {
				sc.ValidateResponse(t, resp.Data)
			}
		})
	}
}
```

## 21.7 Role API Test

### Create Role Scenarios

Buat file `test/api/role/create_test.go` :

```go
//go:build integration

package role_test

import (
	"encoding/json"
	"net/http"
	"testing"
	"workshop/internal/dto"
	"workshop/pkg/errors"
	"workshop/test/setup"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

type RoleCreateScenario struct {
	Name                 string
	Request              dto.RoleRequest
	ExpectHttpStatusCode int
	ExpectBusinessCode   string
	ExpectMessage        string
	ValidateResponse     func(t *testing.T, data json.RawMessage)
}

var roleCreateScenarios = []RoleCreateScenario{
	{
		Name:                 "success",
		Request:              dto.RoleRequest{Name: "kasir"},
		ExpectHttpStatusCode: http.StatusCreated,
		ExpectBusinessCode:   "B1",
		ExpectMessage:        "Created",
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var resp dto.RoleResponse
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)

			assert.Equal(t, "kasir", resp.Name)
			assert.NotEmpty(t, resp.ID)
		},
	},
	{
		Name:                 "internal error - duplicate",
		Request:              dto.RoleRequest{Name: "kasir"},
		ExpectHttpStatusCode: http.StatusInternalServerError,
		ExpectBusinessCode:   errors.InternalServerErrorCode,
		ExpectMessage:        "error creating role",
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			assert.Equal(t, "{}", string(data))
		},
	},
	{
		Name:                 "invalid input - required",
		Request:              dto.RoleRequest{Name: ""},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        errors.InvalidInputMessage,
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var resp map[string]string
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			assert.Contains(t, resp["name"], "Name is required")
		},
	},
	{
		Name:                 "invalid input - too short",
		Request:              dto.RoleRequest{Name: "ka"},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        errors.InvalidInputMessage,
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var resp map[string]string
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			assert.Contains(t, resp["name"], "Name is too short")
		},
	},
	{
		Name:                 "invalid input - too long",
		Request:              dto.RoleRequest{Name: "kasir melebihi 25 karakter"},
		ExpectHttpStatusCode: http.StatusBadRequest,
		ExpectBusinessCode:   errors.InvalidInputCode,
		ExpectMessage:        errors.InvalidInputMessage,
		ValidateResponse: func(t *testing.T, data json.RawMessage) {
			var resp map[string]string
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			assert.Contains(t, resp["name"], "Name is too long")
		},
	},
}

func TestRole_Create(t *testing.T) {
	token := setup.GetToken(t, "admin@example.com", "1234")
	for _, sc := range roleCreateScenarios {
		t.Run(sc.Name, func(t *testing.T) {
			w := setup.CallAPI(t, "POST", "/roles", token, sc.Request)
			defer w.Body.Close()

			assert.Equal(t, sc.ExpectHttpStatusCode, w.StatusCode)

			var resp struct {
				Status  string          `json:"status"`
				Message string          `json:"message"`
				Data    json.RawMessage `json:"data"`
			}
			err := json.NewDecoder(w.Body).Decode(&resp)
			require.NoError(t, err)

			assert.Equal(t, sc.ExpectBusinessCode, resp.Status)
			assert.Equal(t, sc.ExpectMessage, resp.Message)

			if sc.ValidateResponse != nil {
				sc.ValidateResponse(t, resp.Data)
			}
		})
	}
}
```

## 21.8 E2E Testing

Flow E2E Testing untuk RBAC :
1. Login with non-existent user (negative test)
2. Login as admin (prepare pembuatan user baru dari user yang tidak ada di langkah 1)
3. Verify role does NOT exist (mengecek bahwa role baru yang hendak diinput belum ada di database)
4. Create role (membuat role baru)
5. Verify role by ID (memverifikasi role baru telah terbuat)
6. Create user (Membuat user baru dari data user di langkah 1)
7. Verify user by ID (memverifikasi pembuatan user baru berhasil)
8. Login as new user (mendapatkan token dari user yang baru dibuat) 
9. Access /accesses without permission (should be forbidden, sesuai ekspektasi user baru belum punya permissions)
10. Get permission IDs (mendapatkan AccessIDs dari ekspektasi list permission yang hendak di-grant ke role baru, persiapan sebelum memanggil langkah grant access)
11. Grant access to role (memberi akses ke role yang telah dibuat)
12. Access /accesses with permission (should succeed, GET /access seharusnya bisa diakses setelah role di-grant)

### Pembuatan Base Helper

```go
// test/e2e/helper/base_helper.go
package helper

import (
	"encoding/json"
	"testing"
	"workshop/test/setup"

	"github.com/stretchr/testify/require"
)

type RequestConfig[T any] struct {
	Method         string
	Path           string
	Token          string
	Body           interface{}
	ExpectedStatus int
	ExpectedCode   string
	ExpectedMsg    string
	Validate       func(t *testing.T, data json.RawMessage) *T
}

func DoRequest[T any](t *testing.T, cfg RequestConfig[T]) *T {
	w := setup.CallAPI(t, cfg.Method, cfg.Path, cfg.Token, cfg.Body)
	defer w.Body.Close()

	require.Equal(t, cfg.ExpectedStatus, w.StatusCode)

	var resp struct {
		Status  string          `json:"status"`
		Message string          `json:"message"`
		Data    json.RawMessage `json:"data"`
	}
	err := json.NewDecoder(w.Body).Decode(&resp)
	require.NoError(t, err)

	require.Equal(t, cfg.ExpectedCode, resp.Status)
	require.Equal(t, cfg.ExpectedMsg, resp.Message)

	if cfg.Validate != nil {
		return cfg.Validate(t, resp.Data)
	}
	return nil
}
```

### Auth Helper

```go
// test/e2e/helper/auth_helper.go
package helper

import (
	"encoding/json"
	"net/http"
	"testing"
	"workshop/internal/dto"
	"workshop/test/setup"

	"github.com/stretchr/testify/require"
)

func Login(t *testing.T, email, password string) string {
	w := setup.CallAPI(t, "POST", "/login", "", dto.LoginRequest{Username: email, Password: password})
	defer w.Body.Close()

	require.Equal(t, http.StatusOK, w.StatusCode)

	var resp struct {
		Data struct {
			Token string `json:"token"`
		} `json:"data"`
	}
	err := json.NewDecoder(w.Body).Decode(&resp)
	require.NoError(t, err)
	require.NotEmpty(t, resp.Data.Token)

	return resp.Data.Token
}

func LoginExpectError(t *testing.T, email, password string, expectedStatus int, expectedCode, expectedMsg string) {
	DoRequest(t, RequestConfig[any]{
		Method:         "POST",
		Path:           "/login",
		Token:          "",
		Body:           dto.LoginRequest{Username: email, Password: password},
		ExpectedStatus: expectedStatus,
		ExpectedCode:   expectedCode,
		ExpectedMsg:    expectedMsg,
		Validate: func(t *testing.T, data json.RawMessage) *any {
			require.Equal(t, "{}", string(data))
			return nil
		},
	})
}
```

### Role Helper

```go
// test/e2e/helper/role_helper.go
package helper

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"workshop/internal/dto"

	"github.com/stretchr/testify/require"
)

func CreateRole(t *testing.T, token, name string) *dto.RoleResponse {
	return DoRequest(t, RequestConfig[dto.RoleResponse]{
		Method:         "POST",
		Path:           "/roles",
		Token:          token,
		Body:           dto.RoleRequest{Name: name},
		ExpectedStatus: http.StatusCreated,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Created",
		Validate: func(t *testing.T, data json.RawMessage) *dto.RoleResponse {
			var resp dto.RoleResponse
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			require.NotZero(t, resp.ID)
			require.Equal(t, name, resp.Name)
			return &resp
		},
	})
}

func GetRole(t *testing.T, token string, roleID int) *dto.RoleResponse {
	return DoRequest(t, RequestConfig[dto.RoleResponse]{
		Method:         "GET",
		Path:           fmt.Sprintf("/roles/%d", roleID),
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate: func(t *testing.T, data json.RawMessage) *dto.RoleResponse {
			var resp dto.RoleResponse
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			require.Equal(t, roleID, resp.ID)
			return &resp
		},
	})
}

func ListRoles(t *testing.T, token string) []dto.RoleResponse {
	var roles []dto.RoleResponse
	DoRequest(t, RequestConfig[any]{
		Method:         "GET",
		Path:           "/roles",
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate: func(t *testing.T, data json.RawMessage) *any {
			err := json.Unmarshal(data, &roles)
			require.NoError(t, err)
			return nil
		},
	})
	return roles
}

func UpdateRole(t *testing.T, token string, roleID int, newName string) *dto.RoleResponse {
	return DoRequest(t, RequestConfig[dto.RoleResponse]{
		Method:         "PUT",
		Path:           fmt.Sprintf("/roles/%d", roleID),
		Token:          token,
		Body:           dto.RoleRequest{Name: newName},
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate: func(t *testing.T, data json.RawMessage) *dto.RoleResponse {
			var resp dto.RoleResponse
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			require.Equal(t, roleID, resp.ID)
			require.Equal(t, newName, resp.Name)
			return &resp
		},
	})
}

func DeleteRole(t *testing.T, token string, roleID int) {
	DoRequest(t, RequestConfig[any]{
		Method:         "DELETE",
		Path:           fmt.Sprintf("/roles/%d", roleID),
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate:       nil,
	})
}

func RoleExists(t *testing.T, token, name string) bool {
	roles := ListRoles(t, token)
	for _, r := range roles {
		if r.Name == name {
			return true
		}
	}
	return false
}
```

### User Helper

```go
// test/e2e/helper/user_helper.go
package helper

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"workshop/internal/dto"

	"github.com/stretchr/testify/require"
)

func CreateUser(t *testing.T, token, name, email, password string, roles []int) *dto.UserResponse {
	return DoRequest(t, RequestConfig[dto.UserResponse]{
		Method: "POST",
		Path:   "/users",
		Token:  token,
		Body: dto.UserRequest{
			Name:     name,
			Username: email,
			Email:    email,
			Password: password,
			IsActive: true,
			Roles:    roles,
		},
		ExpectedStatus: http.StatusCreated,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Created",
		Validate: func(t *testing.T, data json.RawMessage) *dto.UserResponse {
			var resp dto.UserResponse
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			require.NotEmpty(t, resp.ID)
			require.Equal(t, email, resp.Email)
			return &resp
		},
	})
}

func GetUser(t *testing.T, token, userID string) *dto.UserResponse {
	return DoRequest(t, RequestConfig[dto.UserResponse]{
		Method:         "GET",
		Path:           fmt.Sprintf("/users/%s", userID),
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate: func(t *testing.T, data json.RawMessage) *dto.UserResponse {
			var resp dto.UserResponse
			err := json.Unmarshal(data, &resp)
			require.NoError(t, err)
			require.Equal(t, userID, resp.ID)
			return &resp
		},
	})
}

func DeleteUser(t *testing.T, token, userID string) {
	DoRequest(t, RequestConfig[any]{
		Method:         "DELETE",
		Path:           fmt.Sprintf("/users/%s", userID),
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate:       nil,
	})
}
```

### Access Helper

```go
// test/e2e/helper/access_helper.go
package helper

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
	"workshop/internal/dto"
	"workshop/pkg/errors"

	"github.com/stretchr/testify/require"
)

func ListAccesses(t *testing.T, token string) []dto.AccessTreeResponse {
	var accesses []dto.AccessTreeResponse
	DoRequest(t, RequestConfig[any]{
		Method:         "GET",
		Path:           "/accesses",
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate: func(t *testing.T, data json.RawMessage) *any {
			err := json.Unmarshal(data, &accesses)
			require.NoError(t, err)
			return nil
		},
	})
	return accesses
}

func ListAccessesExpectForbidden(t *testing.T, token string) {
	DoRequest(t, RequestConfig[any]{
		Method:         "GET",
		Path:           "/accesses",
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusForbidden,
		ExpectedCode:   errors.ForbiddenCode,
		ExpectedMsg:    "Forbidden",
		Validate:       nil,
	})
}

func GrantAccess(t *testing.T, token string, roleID, accessID int) {
	DoRequest(t, RequestConfig[any]{
		Method:         "POST",
		Path:           fmt.Sprintf("/roles/%d/access/%d", roleID, accessID),
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate:       nil,
	})
}

func RevokeAccess(t *testing.T, token string, roleID, accessID int) {
	DoRequest(t, RequestConfig[any]{
		Method:         "DELETE",
		Path:           fmt.Sprintf("/roles/%d/access/%d", roleID, accessID),
		Token:          token,
		Body:           nil,
		ExpectedStatus: http.StatusOK,
		ExpectedCode:   "B1",
		ExpectedMsg:    "Success",
		Validate:       nil,
	})
}

func GetAccessIDs(t *testing.T, token string, permissions []string) []int {
	accessIDs := make([]int, 0)
	accesses := ListAccesses(t, token)

	for _, r := range accesses {
		for _, child := range r.Childrens {
			for _, permission := range permissions {
				if child.Alias == permission {
					accessIDs = append(accessIDs, child.ID)
				}
			}
		}
	}
	return accessIDs
}
```

### E2E Test : RBAC Flow

```go
//go:build integration

package rbac_test

import (
	"net/http"
	"testing"
	"workshop/test/e2e/helper"

	"github.com/stretchr/testify/require"
)

func TestRBAC(t *testing.T) {
	const (
		adminEmail = "admin@example.com"
		adminPass  = "1234"
		userEmail  = "manager@example.com"
		userPass   = "1234567890"
		userName   = "manger"
		roleName   = "manager"
	)

	var (
		permissions = []string{
			"accesses::list",
			"roles::list",
			"roles::view",
		}
	)

	// Step 1: Login with non-existent user (negative test)
	helper.LoginExpectError(t, userEmail, userPass, http.StatusBadRequest, "E001", "Invalid username/password")

	// Step 2: Login as admin
	tokenAdmin := helper.Login(t, adminEmail, adminPass)

	// Step 3: Verify role does NOT exist
	require.False(t, helper.RoleExists(t, tokenAdmin, roleName), "Role should not exist yet")

	// Step 4: Create role
	role := helper.CreateRole(t, tokenAdmin, roleName)
	t.Logf("✅ Role created: ID=%d, Name=%s", role.ID, role.Name)

	// Step 5: Verify role by ID
	gotRole := helper.GetRole(t, tokenAdmin, role.ID)
	require.Equal(t, role.Name, gotRole.Name)

	// Step 6: Create user
	user := helper.CreateUser(t, tokenAdmin, userName, userEmail, userPass, []int{role.ID})
	t.Logf("✅ User created: ID=%s", user.ID)

	// Step 7: Verify user by ID
	gotUser := helper.GetUser(t, tokenAdmin, user.ID)
	require.Equal(t, user.Email, gotUser.Email)

	// Step 8: Login as new user (no permissions yet)
	tokenUser := helper.Login(t, userEmail, userPass)

	// Step 9: Access /accesses without permission (should be forbidden)
	helper.ListAccessesExpectForbidden(t, tokenUser)

	// Step 10: Get permission IDs
	permissionIDs := helper.GetAccessIDs(t, tokenAdmin, permissions)
	require.Equal(t, len(permissions), len(permissionIDs), "Length of Permission IDs should be 3")

	// Step 11: Grant access to role
	for _, accessID := range permissionIDs {
		helper.GrantAccess(t, tokenAdmin, role.ID, accessID)
	}

	// Step 12: Access /accesses with permission (should succeed)
	accesses := helper.ListAccesses(t, tokenUser)
	require.NotEmpty(t, accesses, "User should have permissions after grant")
	t.Logf("✅ User now has %d permissions", len(accesses))

	// Cleanup (auto cleanup dengan t.Cleanup)
	t.Cleanup(func() {
		helper.DeleteUser(t, tokenAdmin, user.ID)
		helper.DeleteRole(t, tokenAdmin, role.ID)
		t.Log("✅ Cleanup completed")
	})
}
```


## 21.9 Build Tag

Tambahkan build tag //go:build integration di semua file API test. Ini memungkinkan kita menjalankan unit test dan API test secara terpisah.

**Konfigurasi VS Code:** Buat `.vscode/settings.json` agar build tag dikenali:

```json
{
    "go.buildTags": "integration",
    "go.testTags": "integration",
    "go.testTimeout": "120s",
    "go.testFlags": ["-v", "-count=1"],
    "gopls": {
        "build.env": {
            "GOFLAGS": "-tags=integration"
        },
        "build.directoryFilters": [
            "-node_modules",
            "-vendor",
            "-testdata"
        ],
        "ui.semanticTokens": true,
        "ui.completion.usePlaceholders": true
    }
}
```

kemudian retart gopl. (mac -> shift+cmd+p, muncul promp, kli `GO: Restart Language Server`)

**Menjalankan Test:**


```bash
# Unit test saja (tanpa build tag)
go test ./...

# API test (dengan build tag integration)
go test -tags=integration ./test/api/...

# E2E test
go test -tags=integration ./test/e2e/...

# Semua test (unit + API + E2E)
go test -tags=integration ./...
```

## Ringkasan Bab 21

Di bab ini kita telah belajar:

| Komponen | Lokasi | Fungsi |
|----------|--------|--------|
| PostgreSQL Container | test/containers/postgres.go | Membuat database container on-the-fly |
| Container Registry | test/containers/registry.go | Singleton manager untuk semua container |
| Setup Test | test/setup/setup.go | Inisialisasi server, migrasi, scan access |
| API Helpers | test/setup/setup.go | CallAPI(), GetToken() |
| Auth Test | test/api/auth/ | Test endpoint /login |
| Role Test | test/api/role/ | Test CRUD roles |
| E2E Helpers | test/e2e/helper/ | Helper untuk setiap resource |
| E2E Test | test/e2e/rbac/ | End-to-end flow RBAC |

Manfaat yang kita peroleh:
- ✅ API teruji secara end-to-end dengan database sungguhan
- ✅ Test terisolasi (container on-the-fly)
- ✅ Build tag memisahkan unit test dan integration test
- ✅ Helper yang reusable untuk berbagai skenario

Yang akan datang:
- Saat ini kita belum membahas cache
- Bab selanjutnya: Cache – meningkatkan performa dengan caching