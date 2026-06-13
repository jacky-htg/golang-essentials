# Bab 12: Standard Response

Saat ini, API kita mengembalikan response yang tidak konsisten:
- Saat sukses → mengembalikan JSON data mentah (array user atau object user)
- Saat error → mengembalikan teks biasa melalui http.Error()

Hal ini menyulitkan client (mobile, frontend) dalam memproses response karena struktur yang selalu berubah. Bab ini akan membangun standard response yang seragam untuk semua endpoint.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/12-standard-response](https://github.com/jacky-htg/workshop/tree/main/12-standard-response)

## 12.1 Masalah dengan Response Tidak Terstandar

Response sukses saat ini:

```json
// GET /users
[
    {"id": "123", "name": "John", ...}
]

// POST /users
{"id": "456", "name": "Jane", ...}
```

Response error saat ini:

```text
Internal Server Error
```

| Masalah | Dampak |
|---------|--------|
| Tipe data berbeda | Sukses → array/object, Error → string |
| Tidak ada status business logic | Client tidak tahu apakah operasi benar-benar sukses |
| Tidak ada pesan yang konsisten | Frontend sulit menampilkan error message |

## 12.2 Desain Standard Response

Kita akan mendefinisikan format response JSON yang seragam:

```json
{
    "status": "B1",
    "message": "Success",
    "data": { ... }
}
```

| Field | Deskripsi | Contoh Nilai |
|-------|-----------|--------------|
| status | Status business logic (bukan HTTP status) | "B1" (sukses), "B0" (error) |
| message | Pesan yang ramah untuk user | "User created successfully" |
| data | Payload data (bisa object, array, atau null) | {"id": "123", ...} atau [] atau {} |

**Mengapa status business logic?** HTTP status code (200, 400, 500) untuk infrastruktur. Status "B1"/"B0" untuk logika bisnis. Contoh: login gagal karena password salah → HTTP 200 (request berhasil diproses) tapi status "B0" (business error).

## 12.3 Implementasi Response Helper

Buat file `pkg/response/response.go`:
```go
package response

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"

	"github.com/jacky-htg/go-libs/logger"
)

const (
	AppBusinessStatusSuccess = "B1"
	AppBusinessStatusError   = "B0"
)

type StandardResponse struct {
	Status  string `json:"status"`
	Message string `json:"message"`
	Data    any    `json:"data"`
}

func SetResponse(log logger.Logger, w http.ResponseWriter, httpStatus int, appBusinessLogicStatus string, message string, data any) {
	standardResponse := StandardResponse{
		Status:  appBusinessLogicStatus,
		Message: message,
		Data:    data,
	}

	resp, err := json.Marshal(standardResponse)
	if err != nil {
		log.Error(context.Background(), "error: marshaling users to JSON", slog.Any("error", err))
		httpStatus = http.StatusInternalServerError
		appBusinessLogicStatus = AppBusinessStatusError
		message = "Internal Server Error"
	}

	w.Header().Set("Content-Type", "application/json")
	if httpStatus != http.StatusOK {
		w.WriteHeader(httpStatus)
	}

	if _, err = w.Write(resp); err != nil {
		log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

func SetError(log logger.Logger, w http.ResponseWriter, httpStatus int, appBusinessLogicStatus string, err error, message string) {
	finalMessage := message
	if finalMessage == "" && err != nil {
		finalMessage = err.Error()
	}
	SetResponse(log, w, httpStatus, appBusinessLogicStatus, finalMessage, struct{}{})
}

func SetOk(log logger.Logger, w http.ResponseWriter, data any) {
	SetResponse(log, w, http.StatusOK, AppBusinessStatusSuccess, "Success", data)
}

func SetCreated(log logger.Logger, w http.ResponseWriter, data any) {
	SetResponse(log, w, http.StatusCreated, AppBusinessStatusSuccess, "Created", data)
}
```

## 12.4 Refactor Handler Menggunakan Response Helper

Sekarang semua handler diubah menggunakan helper di atas. Kode menjadi lebih bersih dan konsisten.

### UserHandler.List

Sebelum:

```go
data, err := json.Marshal(response)
w.Header().Set("Content-Type", "application/json")
w.Write(data)
```

Sesudah:

```go
response.SetOk(u.log, w, resp)
```

### UserHandler.Create

Sebelum:

```go
w.WriteHeader(http.StatusCreated)
w.Write(data)
```

Sesudah

```go
response.SetCreated(u.log, w, resp)
```

### UserHandler.FindById

Sebelum:

```go
if user == nil {
    http.Error(w, "Not Found", http.StatusNotFound)
    return
}
```

Sesudah :

```go
if user == nil {
    response.SetError(u.log, w, http.StatusNotFound, response.AppBusinessStatusError, 
                      nil, "User not found")
    return
}
```

## 12.5 Kode Lengkap Handler yang Direfactor

```go
// internal/handler/user_handler.go
package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"

	"workshop/internal/dto"
	"workshop/internal/model"
	"workshop/internal/service"
	"workshop/pkg/response"

	"github.com/jacky-htg/go-libs/logger"
)

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindById(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
	Delete(w http.ResponseWriter, r *http.Request)
}

type userHandler struct {
	log     logger.Logger
	service service.Users
}

func NewUserHandler(service service.Users, log logger.Logger) UserHandler {
	return &userHandler{service: service, log: log}
}

// List : http handler for returning list of users
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	users, err := u.service.List()
	if err != nil {
		u.log.Error(context.Background(), "error: listing users", slog.Any("error", err))
		response.SetError(u.log, w, http.StatusInternalServerError, response.AppBusinessStatusError, err, "Failed to list users")
		return
	}

	var resp []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		resp = append(resp, ur)
	}

	response.SetOk(u.log, w, resp)
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		response.SetError(u.log, w, http.StatusBadRequest, response.AppBusinessStatusError, err, "Invalid request payload")
		return
	}
	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(&user)
	if err != nil {
		response.SetError(u.log, w, http.StatusInternalServerError, response.AppBusinessStatusError, err, "Failed to create user")
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)
	response.SetCreated(u.log, w, resp)
}

// FindById : http handler for finding a user by ID
func (u *userHandler) FindById(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		response.SetError(u.log, w, http.StatusBadRequest, response.AppBusinessStatusError, nil, "Missing id parameter")
		return
	}

	user, err := u.service.FindById(id)
	if err != nil {
		response.SetError(u.log, w, http.StatusInternalServerError, response.AppBusinessStatusError, err, "Failed to find user")
		return
	}
	if user == nil {
		response.SetError(u.log, w, http.StatusNotFound, response.AppBusinessStatusError, nil, "User not found")
		return
	}

	var resp dto.UserResponse
	resp.Transform(*user)

	response.SetOk(u.log, w, resp)
}

func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		response.SetError(u.log, w, http.StatusBadRequest, response.AppBusinessStatusError, nil, "Missing id parameter")
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		response.SetError(u.log, w, http.StatusBadRequest, response.AppBusinessStatusError, nil, "Invalid request payload")
		return
	}
	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(&user)
	if err != nil {
		if err.Error() == "user not found" {
			response.SetError(u.log, w, http.StatusNotFound, response.AppBusinessStatusError, nil, "User not found")
		} else {
			response.SetError(u.log, w, http.StatusInternalServerError, response.AppBusinessStatusError, err, "Failed to update user")
		}
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)

	response.SetOk(u.log, w, resp)
}

func (u *userHandler) Delete(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		response.SetError(u.log, w, http.StatusBadRequest, response.AppBusinessStatusError, nil, "Missing id parameter")
		return
	}

	err := u.service.Delete(id)
	if err != nil {
		if err.Error() == "user not found" {
			response.SetError(u.log, w, http.StatusNotFound, response.AppBusinessStatusError, nil, "User not found")
		} else {
			response.SetError(u.log, w, http.StatusInternalServerError, response.AppBusinessStatusError, err, "Failed to delete user")
		}
		return
	}
	response.SetOk(u.log, w, struct{}{})
}
```

## 12.6 Contoh Response Setelah Standardisasi

### Response Sukses (GET /users)

```json
{
    "status": "B1",
    "message": "Success",
    "data": [
        {"id": "123", "name": "John Doe", ...},
        {"id": "456", "name": "Jane Smith", ...}
    ]
}
```

### Response Sukses (GET /users/{id})

```json
{
    "status": "B1",
    "message": "Success",
    "data": {"id": "123", "name": "John Doe", ...}
}
```

### Response Sukses (POST /users)

```json
{
    "status": "B1",
    "message": "Created",
    "data": {"id": "789", "name": "New User", ...}
}
```

### Response Sukses (DELETE /users/{id})

```json
{
    "status": "B1",
    "message": "Success",
    "data": {}
}
```

### Response Error (User Not Found)

```json
{
    "status": "B0",
    "message": "User not found",
    "data": {}
}
```

### Response Error (Invalid Request)

```json
{
    "status": "B0",
    "message": "Invalid request payload",
    "data": {}
}
```

## 12.7 Manfaat yang Diperoleh

| Sebelum | Sesudah |
|---------|---------|
| Response tidak konsisten | Semua response memiliki format seragam |
| Error berupa teks biasa | Error juga dalam format JSON |
| Kode repetitive (Marshal + Header + Write) | Satu baris SetOk() atau SetError() |
| Status business logic tidak ada | Status B1/B0 untuk logika bisnis |
| Perubahan format susah | Cukup ubah satu fungsi SetResponse() |

## Ringkasan Bab 12 

Di bab ini kita telah belajar:
1. Standard Response Pattern – Format JSON seragam untuk semua endpoint
2. Business Status Code – B1 (sukses) dan B0 (error)
3. Helper Functions – SetOk(), SetCreated(), SetError() untuk konsistensi
4. Refactoring Handler – Kode menjadi lebih bersih dan mudah dipelihara

Manfaat yang kita peroleh:
- ✅ Frontend bisa memproses semua response dengan cara yang sama
- ✅ Error message informatif dan terstruktur
- ✅ Mudah menambahkan field baru ke semua response (misal: request_id)
- ✅ Mengurangi duplikasi kode (DRY principle)

Yang akan datang:
- Saat ini error handling masih sederhana (semua error balik ke client dengan status B0)
- Bab selanjutnya: Error Handler – membangun sistem error handling yang lebih canggih dengan custom error types dan proper error wrapping
