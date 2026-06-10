# Bab 11: CRUD

Setelah memiliki routing yang rapi, kini saatnya melengkapi operasi CRUD (Create, Read, Update, Delete) untuk resource User. Bab ini akan membangun kelima endpoint REST API yang umum:

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | /users` | Mendaftar semua user |
| POST | /users | Membuat user baru |
| GET | /users/{id} | Membaca detail user |
| PUT | /users/{id} | Mengupdate user |
| DELETE | /users/{id} | Menghapus user (soft delete) |

## 11.1 Create User

Operasi Create adalah yang paling kompleks karena melibatkan:
1. Hashing password – menggunakan bcrypt (tidak boleh disimpan plain text)
2. Generate ID – menggunakan UUID v7 (terurut berdasarkan waktu)
3. Validasi input – akan dibahas di bab terpisah

### 11.1.1 Repository Layer – Create

Tambahkan method Create ke UserRepository interface dan implementasinya:

```go
// internal/repository/user_repository.go
type UserRepository interface {
    List() ([]model.User, error)
    Create(*model.User) error  // ← tambahan
    // ... method lainnya
}

func (u *userRepository) Create(user *model.User) error {
    query := `INSERT INTO users (id, name, username, password, email, is_active) 
              VALUES ($1, $2, $3, $4, $5, $6)`
    _, err := u.db.Exec(query, user.ID, user.Name, user.Username, 
                        user.Password, user.Email, user.IsActive)
    if err != nil {
        u.log.Error(context.Background(), "error: inserting user", slog.Any("error", err))
        return err
    }
    return nil
}
```

### 11.1.2 Service Layer – Create dengan Business Logic

Service layer bertanggung jawab untuk hashing password dan generate ID:

```go
// internal/service/users.go
package service

import (
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

func (u *users) Create(user *model.User) error {

	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(context.Background(), "error generate password", slog.Any("error", err))
		return err
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	if err := u.repo.Create(user); err != nil {
		return err
	}

	return nil
}
```

**Catatan:** `bcrypt.DefaultCost` adalah nilai 10. Untuk keamanan lebih tinggi, bisa ditingkatkan (dengan konsekuensi performa lebih lambat).

### 11.1.3 DTO – UserRequest (Input)

Buat DTO khusus untuk menerima input dari client. Ini memisahkan struktur request dari model database:

```go
// internal/dto/user_request.go
package dto

import "workshop/internal/model"

type UserRequest struct {
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

func (u *UserRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.Username = u.Username
	user.Password = u.Password
	user.Email = u.Email
	user.IsActive = u.IsActive
}
```

### 11.1.4 Handler Layer – Create

Handler bertugas menerima request, memparsing JSON, memanggil service, dan mengembalikan response:

```go
// internal/handler/user_handler.go
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}

	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(&user)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

**Status code:** 201 Created adalah status yang tepat untuk operasi Create.


## 11.2 Read (Get by ID)

### 11.2.1 Repository – FindById

```go
// internal/repository/user_repository.go
func (u *userRepository) FindById(id string) (*model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE id = $1`
	row := u.db.QueryRow(query, id)

	var user model.User
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}
```

### 11.2.2 Service – FindById

```go
// internal/service/users.go
func (u *users) FindById(id string) (*model.User, error) {
	return u.repo.FindById(id)
}
```

### 11.2.3 Handler – FindById dengan Path Parameter

Go 1.22+ menyediakan `r.PathValue("id")` untuk mengambil parameter dari URL:

* Ubah file `internal/handler/user_handler.go` untuk menambhakan method FindById

```go
// internal/handler/user_handler.go
func (u *userHandler) FindById(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	user, err := u.service.FindById(id)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}
	if user == nil {
		http.Error(w, "Not Found", http.StatusNotFound)
		return
	}

	var response dto.UserResponse
	response.Transform(*user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

## 11.3 Update
### 11.3.1 DTO – UserUpdateRequest

Untuk update, kita hanya mengizinkan field tertentu yang bisa diubah:

```go
// internal/dto/user_request.go
type UserUpdateRequest struct {
	Name     string `json:"name"`
	IsActive bool   `json:"is_active"`
}

func (u *UserUpdateRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.IsActive = u.IsActive
}
```

### 11.3.2 Repository – Update dengan RETURNING

```go
// internal/repository/user_repository.go
func (u *userRepository) Update(user *model.User) error {
	query := `UPDATE users SET name = $1, is_active = $2 WHERE id = $3 RETURNING username, email`
	err := u.db.QueryRow(query, user.Name, user.IsActive, user.ID).Scan(&user.Username, &user.Email)
	if err != nil {
		u.log.Error(context.Background(), "error: updating user", slog.Any("error", err))
		return err
	}

	return nil
}
```

**RETURNING clause:** Mengambil field yang tidak diubah (username, email) untuk mempertahankan nilainya di struct user.

### 11.3.3 Service – Update dengan Validasi Keberadaan

```go
// internal/service/users.go
func (u *users) Update(user *model.User) error {
	existUser, err := u.repo.FindById(user.ID)
	if err != nil {
		return err
	}
	if existUser == nil {
		return fmt.Errorf("user not found")
	}
	return u.repo.Update(user)
}
```

### 11.3.4 Handler – Update

```go
// internal/handler/user_handler.go
func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}
	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(&user)
	if err != nil {
		if err.Error() == "user not found" {
			http.Error(w, "Not Found", http.StatusNotFound)
		} else {
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		}
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

## 11.4 Delete (Soft Delete)

### 11.4.1 Konsep Soft Delete

Soft delete berarti data tidak dihapus secara fisik, hanya ditandai sebagai terhapus dengan mengisi field deleted_at. Keuntungan:
- Data bisa dipulihkan
- Audit trail (kapan dihapus)
- Referensi integritas tetap terjaga

Perubahan pada query `List` dan `FindById` (hanya menampilkan data yang belum terhapus):

```go
// List: tambah WHERE deleted_at IS NULL
query := `SELECT ... FROM users WHERE deleted_at IS NULL`

// FindById: tambah kondisi yang sama
query := `SELECT ... FROM users WHERE id = $1 AND deleted_at IS NULL`
```

### 11.4.2 Repository – Delete (Soft)

```go
// internal/repository/user_repository.go
func (u *userRepository) Delete(id string) error {
	query := `UPDATE users SET deleted_at = timezone('utc', now()) WHERE id = $1`
	_, err := u.db.Exec(query, id)
	if err != nil {
		u.log.Error(context.Background(), "error: deleting user", slog.Any("error", err))
		return err
	}

	return nil
}
```

### 11.4.3 Service – Delete

```go
// internal/service/users.go
func (u *users) Delete(id string) error {
	existUser, err := u.repo.FindById(id)
	if err != nil {
		return err
	}
	if existUser == nil {
		return fmt.Errorf("user not found")
	}
	return u.repo.Delete(id)
}
```

### 11.4.4 Handler – Delete (No Content)

```go
// internal/handler/user_handler.go
func (u *userHandler) Delete(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	err := u.service.Delete(id)
	if err != nil {
		if err.Error() == "user not found" {
			http.Error(w, "Not Found", http.StatusNotFound)
		} else {
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		}
		return
	}

	w.WriteHeader(http.StatusNoContent)
}
```

**Status code:** 204 No Content adalah response yang tepat untuk DELETE karena tidak ada data yang dikembalikan.

## 11.5 Routing Lengkap

Update `internal/router/api.go` dengan semua endpoint:

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(userRepository, log)
	userHandler := handler.NewUserHandler(userService, log)
	mux.HandleFunc("GET /users", userHandler.List)
	mux.HandleFunc("POST /users", userHandler.Create)
	mux.HandleFunc("GET /users/{id}", userHandler.FindById)
	mux.HandleFunc("PUT /users/{id}", userHandler.Update)
	mux.HandleFunc("DELETE /users/{id}", userHandler.Delete)

	return mux
}
```

## 11.6 Testing CRUD dengan cURL

### Create User

```bash
curl -X POST localhost:9000/users \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "username": "johndoe",
    "email": "john@example.com",
    "password": "secret123",
    "is_active": true
  }'
  ```

**Response:** 201 Created + data user (tanpa password)

### List Users

```bash
curl localhost:9000/users
```

### Get User by ID

```bash
curl localhost:9000/users/{id}
```

### Update User

```bash
curl -X PUT localhost:9000/users/{id} \
  -H "Content-Type: application/json" \
  -d '{"name": "John Updated", "is_active": false}'
```

### Delete User

```bash
curl -X DELETE localhost:9000/users/{id}
```

## Ringkasan Bab 11

Di bab ini kita telah melengkapi semua operasi CRUD:

| Operasi | Method | Endpoint | Status Code |
|---------|--------|----------|-------------|
| Create | POST | /users | 201 Created |
| List | GET | /users | 200 OK |
| Read | GET | /users/{id} | 200 OK / 404 Not Found |
| Update | PUT | /users/{id} | 200 OK / 404 Not Found |
| Delete | DELETE | /users/{id} | 204 No Content / 404 Not Found |

Penting yang dipelajari:
- ✅ Hashing password dengan bcrypt
- ✅ Generate UUID v7 untuk ID terurut
- ✅ Soft delete pattern dengan deleted_at
- ✅ Path parameter dengan r.PathValue()
- ✅ RETURNING clause untuk mengambil data setelah update
- ✅ DTO terpisah untuk Create (full) vs Update (partial)

Yang akan datang:
- Saat ini error handling masih sederhana (http.Error)
- Bab selanjutnya: Standard Response – membangun format response JSON yang konsisten di seluruh API