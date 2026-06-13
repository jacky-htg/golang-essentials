# Bab 19: Pagination

Saat jumlah data dalam database membesar (ratusan, ribuan, atau jutaan), mengembalikan semua data dalam satu response adalah ide yang buruk karena:

- Response menjadi sangat besar (berat di bandwidth)
- Client lambat memproses data
- Database terbebani oleh query besar
- Pengalaman pengguna menurun

Pagination adalah solusi untuk memecah data menjadi halaman-halaman kecil.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/19-pagination](https://github.com/jacky-htg/workshop/tree/main/19-pagination)

## 19.1 Desain Pagination

Query Parameters yang Didukung

| Parameter | Contoh | Deskripsi |
|-----------|--------|-----------|
| page | page=2 | Halaman yang diminta (default: 1) |
| limit | limit=20 | Jumlah data per halaman (default: 10) |
| order | order=name | Field untuk sorting |
| sort | sort=desc | Arah sorting (asc atau desc, default: asc) |
| search | search=joh | Pencarian teks (case-insensitive) |

**Contoh Request**

```bash
GET /users?page=2&limit=5&order=name&sort=asc&search=admin
```

**Struktur Response dengan Meta**

```json
{
    "status": "B1",
    "message": "Success",
    "data": [...],
    "meta": {
        "order": "name",
        "sort": "asc",
        "search": "admin",
        "pagination": {
            "page": 2,
            "limit": 5,
            "total": 25,
            "total_pages": 5,
            "has_next": true,
            "has_prev": true
        }
    }
}
```

## 19.2 Update Standard Response untuk Meta

Tambahkan field `Meta` ke `StandardResponse`:

```go
// pkg/response/response.go
package response

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
)

const AppBusinessStatusSuccess = "B1"

type StandardResponse struct {
	Status  string `json:"status"`
	Message string `json:"message"`
	Data    any    `json:"data"`
	Meta    any    `json:"meta,omitempty"`
}

func SetResponse(ctx context.Context, log logger.Logger, w http.ResponseWriter, httpStatus int, appBusinessLogicStatus string, message string, data ...any) {
	var dataResp any
	dataResp = struct{}{}

	if len(data) > 0 {
		dataResp = data[0]
	}
	standardResponse := StandardResponse{
		Status:  appBusinessLogicStatus,
		Message: message,
		Data:    dataResp,
	}

	if len(data) > 1 {
		standardResponse.Meta = data[1]
	}

	resp, err := json.Marshal(standardResponse)
	if err != nil {
		log.Error(ctx, "error: marshaling users to JSON", slog.Any("error", err))
		httpStatus = http.StatusInternalServerError
		appBusinessLogicStatus = errors.InternalServerErrorCode
		message = "Internal Server Error"
	}

	w.Header().Set("Content-Type", "application/json")
	if httpStatus != http.StatusOK {
		w.WriteHeader(httpStatus)
	}

	if _, err = w.Write(resp); err != nil {
		log.Error(ctx, "error: writing response", slog.Any("error", err))
	}
}

func SetError(ctx context.Context, log logger.Logger, w http.ResponseWriter, err *errors.BusinessError, data any, message ...string) {
	finalMessage := ""
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}

	if finalMessage == "" && err != nil {
		finalMessage = err.Message
	}

	if data == nil {
		data = struct{}{}
	}
	SetResponse(ctx, log, w, err.HTTPStatus, err.Code, finalMessage, data)
}

func SetOk(ctx context.Context, log logger.Logger, w http.ResponseWriter, data ...any) {
	SetResponse(ctx, log, w, http.StatusOK, AppBusinessStatusSuccess, "Success", data...)
}

func SetCreated(ctx context.Context, log logger.Logger, w http.ResponseWriter, data ...any) {
	SetResponse(ctx, log, w, http.StatusCreated, AppBusinessStatusSuccess, "Created", data...)
}
```

## 19.3 Helper Pagination

Buat package pagination untuk mengekstrak parameter dari URL dan membuat meta response:

```go
// pkg/pagination/pagination.go
package pagination

import (
	"net/http"
	"strconv"
	"strings"
	"workshop/internal/dto"
	"workshop/internal/model"
)

func ExtractPaginationFromURL(r *http.Request, defaultOrder ...string) (page, limit int, order, sort, search string) {
	page, _ = strconv.Atoi(r.URL.Query().Get("page"))
	limit, _ = strconv.Atoi(r.URL.Query().Get("limit"))
	order = strings.ToLower(r.URL.Query().Get("order"))
	sort = strings.ToLower(r.URL.Query().Get("sort"))
	search = strings.ToLower(r.URL.Query().Get("search"))

	if page < 1 {
		page = 1
	}

	if limit < 1 {
		limit = 10
	}

	if len(order) == 0 {
		if len(defaultOrder) > 0 {
			order = defaultOrder[0]
		} else {
			order = "id"
		}
	}

	if len(sort) == 0 || !(sort == "asc" || sort == "desc") {
		sort = "asc"
	}

	return
}

func GetMeta(search, order, sort string, pagination model.Pagination) dto.MetaResponse {
	meta := dto.MetaResponse{
		Order: order,
		Sort:  sort,
	}

	if len(search) > 0 {
		meta.Search = search
	}

	var paginationResp dto.PaginationResponse
	paginationResp.Transform(pagination)
	meta.Pagination = paginationResp
	return meta
}
```

## 19.4 DTO untuk Pagination

```go
// internal/dto/pagination_response
package dto

import "workshop/internal/model"

type MetaResponse struct {
	Order      string             `json:"order,omitempty"`
	Sort       string             `json:"sort,omitempty"`
	Search     string             `json:"search,omitempty"`
	Filter     any                `json:"filter,omitempty"`
	Pagination PaginationResponse `json:"pagination,omitempty"`
}

type PaginationResponse struct {
	Page       int  `json:"page"`
	Limit      int  `json:"limit"`
	Total      int  `json:"total"`
	TotalPages int  `json:"total_pages"`
	HasNext    bool `json:"has_next"`
	HasPrev    bool `json:"has_prev"`
}

func (u *PaginationResponse) Transform(pagination model.Pagination) {
	u.Page = pagination.Page
	u.Limit = pagination.Limit
	u.Total = pagination.Count
	u.TotalPages = (u.Total + u.Limit - 1) / u.Limit
	u.HasNext = u.Page < u.TotalPages
	u.HasPrev = u.Page > 1
}
```

## 19.5 Model Pagination

```go
package model

type Pagination struct {
	Page  int
	Limit int
	Count int
}
```

## 19.6 Repository dengan Pagination

Update repository untuk mendukung search, order, sort, limit, dan offset:

```go
// internal/repository/user_repository.go
type UserRepository interface {
	List(ctx context.Context, search, order, sort string, limit, offset int) ([]model.User, int, error)
	// ... kode lainnya tetap sama
}

func (u *userRepository) List(ctx context.Context, search, order, sort string, limit, offset int) ([]model.User, int, error) {
	conditions := []string{"deleted_at IS NULL"}
	args := []any{}

	if len(search) > 0 {
		conditions = append(conditions, fmt.Sprintf(`(name ILIKE $%d)`, len(args)+1))
		args = append(args, "%"+search+"%")
	}

	conditionStr := strings.Join(conditions, " AND ")

	var count int
	err := u.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM users WHERE `+conditionStr, args...).Scan(&count)
	if err != nil {
		u.log.Error(ctx, "error: querying count users", slog.Any("error", err))
		return nil, count, err
	}

	orderByMap := map[string]string{
		"id":         "id",
		"name":       "LOWER(name)",     // Case-insensitive
		"username":   "LOWER(username)", // Case-insensitive
		"email":      "LOWER(email)",    // Case-insensitive
		"is_active":  "is_active",
		"created_at": "created_at",
	}

	order, ok := orderByMap[order]
	if !ok {
		order = "id"
	}

	query := `SELECT id, name, username, password, email, is_active FROM users WHERE ` + conditionStr
	query += fmt.Sprintf(` ORDER BY %s %s LIMIT %d OFFSET %d`, order, sort, limit, offset)

	rows, err := u.db.QueryContext(ctx, query, args...)
	if err != nil {
		u.log.Error(ctx, "error: querying users", slog.Any("error", err))
		return nil, count, err
	}
	defer rows.Close()

	var users []model.User = make([]model.User, 0)
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
			return nil, count, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(ctx, "error: iterating user rows", slog.Any("error", err))
		return nil, count, err
	}

	return users, count, nil
}
```

## 19.7 Service dengan Pagination

```go
// internal/service/users.go
type Users interface {
	List(ctx context.Context, search, order, sort string, limit, page int) ([]model.User, model.Pagination, *errors.BusinessError)
    // .... kode lainnya tetap sama
}

func (u *users) List(ctx context.Context, search, order, sort string, limit, page int) ([]model.User, model.Pagination, *errors.BusinessError) {
	pagination := model.Pagination{Page: page, Limit: limit}
	offset := (pagination.Page - 1) * pagination.Limit

	users, count, err := u.repo.List(ctx, search, order, sort, pagination.Limit, offset)
	if err != nil {
		return nil, pagination, errors.InternalServerErrorWrap(err, "error listing users")
	}
	pagination.Count = count
	return users, pagination, nil
}
```

## 19.8 Handler dengan Pagination

```go
// internal/handler/user_handler.go
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	page, limit, order, sort, search := paginationLib.ExtractPaginationFromURL(r, "name")
	users, pagination, err := u.service.List(ctx, search, order, sort, limit, page)
	if err != nil {
		u.log.Error(ctx, "error: listing users", slog.Any("error", err))
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp []dto.UserResponse = make([]dto.UserResponse, 0)
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		resp = append(resp, ur)
	}

	meta := paginationLib.GetMeta(search, order, sort, pagination)
	response.SetOk(ctx, u.log, w, resp, meta)
}
```

## 19.9 Contoh Request dan Response


```bash
curl 'localhost:9000/users?page=1&limit=10&order=name&sort=asc&search=admin' \
  -H 'Authorization: Bearer <token>'
```

**Response:**

```json
{
    "status": "B1",
    "message": "Success",
    "data": [
        {
            "id": "019eb960-a27d-73c8-9703-b23a9f50dc83",
            "name": "Admin",
            "username": "admin",
            "email": "admin@example.com",
            "is_active": true
        },
        {
            "id": "019ebbc3-fc6e-70f1-81ff-fa93d0bd2e64",
            "name": "admin3",
            "username": "admin3",
            "email": "admin3@example.com",
            "is_active": false
        },
        {
            "id": "019ebbc5-2735-7135-9160-79787fd28985",
            "name": "admin4",
            "username": "admin4",
            "email": "admin4@example.com",
            "is_active": false
        }
    ],
    "meta": {
        "order": "name",
        "sort": "asc",
        "pagination": {
            "page": 1,
            "limit": 10,
            "total": 6,
            "total_pages": 1,
            "has_next": false,
            "has_prev": false
        }
    }
}
```

**Request Tanpa Parameter (Menggunakan Default)**

```bash
curl 'localhost:9000/users' -H 'Authorization: Bearer <token>'
```

- page=1
- limit=10
- order=name
- sort=asc

## 19.10 Keamanan: Mencegah SQL Injection

Perhatikan penggunaan whitelist mapping untuk field order:

```go
orderByMap := map[string]string{
    "id":         "id",
    "name":       "LOWER(name)",
    "username":   "LOWER(username)",
    "email":      "LOWER(email)",
    "is_active":  "is_active",
    "created_at": "created_at",
}

order, ok := orderByMap[order]
if !ok {
    order = "id"  // default jika tidak ada di whitelist
}
```

Ini mencegah attacker menyisipkan SQL injection melalui parameter order.

## 19.11 Catatan: Kapan Pagination Tidak Diperlukan

Untuk resource dengan jumlah data sedikit (misal: roles, config), pagination tidak diperlukan. Juga untuk list access yang mengembalikan semua data dalam format tree, pagination tidak diperlukan.

## Ringkasan Bab 19

Di bab ini kita telah belajar:

| Komponen | File | Fungsi |
|----------|------|--------|
| Response Enhancement | `pkg/response/response.go` | Tambahan field Meta |
| Pagination Helper | `pkg/pagination/pagination.go` | Extract URL params & build meta |
| Pagination DTO | `internal/dto/pagination_response.go` | MetaResponse, PaginationResponse |
| Pagination Model | `internal/model/pagination.go` | Struct untuk internal |
| Repository | `user_repository.go` | COUNT query + LIMIT/OFFSET |
| Service | `users.go` | Hitung offset, return pagination |
| Handler | `user_handler.go` | Extract params, response dengan meta |

Parameter yang Didukung:

| Parameter | Default | Contoh |
|-----------|---------|--------|
| page | 1 | page=3 |
| limit | 10 | limit=25 |
| order | name | order=email |
| sort | asc | sort=desc |
| search | (none) | search=joh |

Manfaat yang kita peroleh:
- ✅ Response lebih ringan (hanya data yang dibutuhkan)
- ✅ Client bisa menampilkan navigasi halaman
- ✅ Sorting dan pencarian terintegrasi
- ✅ Proteksi SQL injection dengan whitelist field
- ✅ Konsistensi response dengan field meta

Yang akan datang:
- Saat ini validasi masih sederhana
- Bab selanjutnya: Unit Testing – menguji kode secara otomatis