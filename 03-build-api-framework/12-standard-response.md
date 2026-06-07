# Standard Response

* Saat ini aplikasi kita akan mengembalikan response berupa text ketika terjadi error, dan mengembalikan json ketika sukses.
* Sering ada permintaan untuk membuat standard response yang sama ketika terjadi error maupun sukses.
* Saat ini set/write response adalah suatu kode yang ditulis berulang-ulang karena sering dipanggil. Untuk efisiensi dan memudahkan pemeliharaan kode, serta memenuhi best practice standrd response kita akan membuat helper standard response.
* response akan distandrdkan dengan mengembalikan json berisi status (kode status versi busnis), message dan data.
* Buat file `pkg/respnse/response.go` yang berisi :

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

* Update file `internal/handler/user_handler.go` menjadi :

```go
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