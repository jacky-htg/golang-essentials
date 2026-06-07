# Error Handler

Tidak semua error adalah "internal server error". Kita harus menghandle berbagai jenis error yang muncul. Pada bab ini kita akan menghandle semua jenis error dengan standar format seperti berikut :

```go
{
    "status_code": "REBEL-404",
    "status_message": "Data Not Found",
    "data": null
}
```

## Custome Error

* Buat custome error yang mengimplementasikan error interface. Custome error yang dibuat mempunyai field :

```go
type Error struct {
    Err           error
    Status        string
    MessageStatus string
    HTTPStatus    int
}
```

* Karena mengimplementasikan interface error, maka custome error yang dibuat harus mengimplementasikan method `func Error() string`

```go
func (err *Error) Error() string {
    return err.Err.Error()
}
```

* Untuk mempermudah saat pembuatan custome error, kita akan melengkapi fungsi dengan fungsi ErrBadRequest, ErrNotFound, dan ErrForbidden.
* Berikut file baru libraries/api/error.go yang berisi :

```go
package api

import "net/http"

// ErrorResponse is the form used for API responses from failures in the API.
type ErrorResponse struct {
    Error string `json:"error"`
}

// Error is used to pass an error during the request through the
// application with web specific context.
type Error struct {
    Err           error
    Status        string
    MessageStatus string
    HTTPStatus    int
}

// ErrNew wraps a provided error with an HTTP status code and custome status code. This
// function should be used when handlers encounter expected errors.
func ErrNew(err error, status string, messageStatus string, httpStatus int) error {
    return &Error{err, status, messageStatus, httpStatus}
}

// ErrBadRequest wraps a provided error with an HTTP status code and custome status code for bad request. This
// function should be used when handlers encounter expected errors.
func ErrBadRequest(err error, message string) error {
    if len(message) <= 0 || message == "" {
        message = StatusMessageBadRequest
    }
    return &Error{err, StatusCodeBadRequest, message, http.StatusBadRequest}
}

// ErrNotFound wraps a provided error with an HTTP status code and custome status code for not found. This
// function should be used when handlers encounter expected errors.
func ErrNotFound(err error, message string) error {
    if len(message) <= 0 || message == "" {
        message = StatusMessageNotFound
    }
    return &Error{err, StatusCodeNotFound, message, http.StatusNotFound}
}

// ErrForbidden wraps a provided error with an HTTP status code and custome status code for forbidden. This
// function should be used when handlers encounter expected errors.
func ErrForbidden(err error, message string) error {
    if len(message) <= 0 || message == "" {
        message = StatusMessageForbidden
    }
    return &Error{err, StatusCodeForbidden, message, http.StatusForbidden}
}

// Error implements the error interface. It uses the default message of the
// wrapped error. This is what will be shown in the services' logs.
func (err *Error) Error() string {
    return err.Err.Error()
}
```

* Kode di atas error karena kita memakai beberapa konstanta yang belum dibuat. Buatlah file libraries/api/status\_code.go untuk menyimpan konstanta status code.

```go
package api

const (
    // StatusCodeOK is custome status code for ok
    StatusCodeOK string = "REBEL-200"

    // StatusCodeBadRequest is custome status code for bad request
    StatusCodeBadRequest string = "REBEL-400"

    // StatusCodeForbidden is custome status code for forbidden
    StatusCodeForbidden string = "REBEL-401"

    // StatusCodeInternalServerError is custome status for unkown error / internal server error
    StatusCodeInternalServerError string = "REBEL-500"

    // StatusCodeNotFound is custome status code for not found
    StatusCodeNotFound string = "REBEL-404"
)
```

* Buat file baru libraries/api/status\_message.go untuk menyimpan konstanta status message.

```go
package api

const (
    // StatusMessageOK is custome status message for ok
    StatusMessageOK string = "OK"

    // StatusMessageBadRequest is custome status message for bad request
    StatusMessageBadRequest string = "Bad Request"

    // StatusMessageInternalServerError is custome status message for unknown error / internal server error
    StatusMessageInternalServerError string = "Internal Error"

    // StatusMessageNotFound is custome status message for data not found
    StatusMessageNotFound string = "Not Found"

    // StatusMessageForbidden is custome status message for forbidden
    StatusMessageForbidden string = "Forbidden"
)
```

* Ubah api.Decode pada file libraries/api/request.go agar mengembalikan custome error dengan status "400 Bad Request"

```go
package api

import (
    "encoding/json"
    "net/http"
)

// Decode reads the body of an HTTP request looking for a JSON document. The
// body is decoded into the provided value.
func Decode(r *http.Request, val interface{}) error {
    if err := json.NewDecoder(r.Body).Decode(val); err != nil {
        return ErrBadRequest(err, "")
    }

    return nil
}
```

* Kemudian setiap error harus didefinisikan dengan jelas merupakan error custome apa. Ubah method Get pada file models/user.go agar mengembalikan ErrNotFound 

```go
// Get user by id
func (u *User) Get(db *sql.DB) error {
    const q string = `SELECT id, username, password, email, is_active FROM users`
    err := db.QueryRow(q+" WHERE id=?", u.ID).Scan(&u.ID, &u.Username, &u.Password, &u.Email, &u.IsActive)

    if err == sql.ErrNoRows {
        err = api.ErrNotFound(err, "")
    }

    return err
}
```

* Ubah file usecases/user\_usecase.go agar error password not match diganti menjadi ErrBadRequest

```go
package usecases

import (
    "database/sql"
    "errors"
    "essentials/libraries/api"
    "essentials/payloads/request"
    "essentials/payloads/response"
    "log"
    "net/http"

    "golang.org/x/crypto/bcrypt"
)

// UserUsecase struct
type UserUsecase struct {
    Log *log.Logger
    Db  *sql.DB
}

// Create new user
func (u *UserUsecase) Create(r *http.Request) (response.UserResponse, error) {
    var userRequest request.NewUserRequest
    var res response.UserResponse

    err := api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        return res, err
    }

    if userRequest.Password != userRequest.RePassword {
        err = api.ErrBadRequest(errors.New("Password not match"), "")
        u.Log.Printf("error : %s", err)
        return res, err
    }

    pass, err := bcrypt.GenerateFromPassword([]byte(userRequest.Password), bcrypt.DefaultCost)
    if err != nil {
        u.Log.Printf("error generate password: %s", err)
        return res, err
    }

    userRequest.Password = string(pass)

    user := userRequest.Transform()

    err = user.Create(u.Db)
    if err != nil {
        u.Log.Printf("error call create user: %s", err)
        return res, err
    }

    res.Transform(user)
    return res, nil
}
```

## Response Format

* Edit file libraries/api/response.go untuk mengubah format response mengikuti struct berikut :

```go
type ResponseFormat struct {
    StatusCode string      `json:"status_code"`
    Message    string      `json:"status_message"`
    Data       interface{} `json:"data"`
}
```

* Ubah fungsi Response di file libraries/api/response.go agar mendukung format yang baru

```go
// Response converts a Go value to JSON and sends it to the client.
func Response(w http.ResponseWriter, data interface{}, statusCode string, message string, httpCode int) error {

    // Convert the response value to JSON.
    res, err := json.Marshal(ResponseFormat{StatusCode: statusCode, Message: message, Data: data})
    if err != nil {
        return err
    }

    // Respond with the provided JSON.
    w.Header().Set("Content-Type", "application/json; charset=utf-8")
    w.WriteHeader(httpCode)
    if _, err := w.Write(res); err != nil {
        return err
    }

    return nil
}
```

* Dan kita akan membuat dua response, yaitu ResponseOK dan ResponseError, untuk itu kita edit file libraries/api/response.go untuk menambahkan dua fungsi response yang baru.

```go
// ResponseOK converts a Go value to JSON and sends it to the client.
func ResponseOK(w http.ResponseWriter, data interface{}, HTTPStatus int) error {
    return Response(w, data, StatusCodeOK, StatusMessageOK, HTTPStatus)
}

// ResponseError sends an error reponse back to the client.
func ResponseError(w http.ResponseWriter, err error) error {

    // If the error was of the type *Error, the handler has
    // a specific status code and error to return.
    if webErr, ok := err.(*Error); ok {
        if err := Response(w, nil, webErr.Status, webErr.MessageStatus, webErr.HTTPStatus); err != nil {
            return err
        }
        return nil
    }

    // If not, the handler sent any arbitrary error value so use 500.
    if err := Response(w, nil, StatusCodeInternalServerError, StatusMessageInternalServerError, http.StatusInternalServerError); err != nil {
        return err
    }
    return nil
}
```

* Ubah file controllers/users.go agar memanggil fungsi response yang baru : ResponseOK atau ResponseError

```go
package controllers

import (
    "database/sql"
    "essentials/libraries/api"
    "essentials/models"
    "essentials/payloads/request"
    "essentials/payloads/response"
    "essentials/usecases"
    "log"
    "net/http"
    "strconv"

    "github.com/julienschmidt/httprouter"
)

// Users : struct for set Users Dependency Injection
type Users struct {
    Db  *sql.DB
    Log *log.Logger
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
    user := new(models.User)
    list, err := user.List(u.Db)
    if err != nil {
        u.Log.Println("get user list", err)
        api.ResponseError(w, err)
        return
    }

    var respList []response.UserResponse
    for _, l := range list {
        var resp response.UserResponse
        resp.Transform(&l)
        respList = append(respList, resp)
    }

    api.ResponseOK(w, respList, http.StatusOK)
}

// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request) {

    uc := usecases.UserUsecase{Log: u.Log, Db: u.Db}
    resp, err := uc.Create(r)
    if err != nil {
        api.ResponseError(w, err)
        return
    }

    api.ResponseOK(w, resp, http.StatusCreated)
}

// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        api.ResponseError(w, err)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        api.ResponseError(w, err)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(user)
    api.ResponseOK(w, resp, http.StatusOK)
}

// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        api.ResponseError(w, err)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        api.ResponseError(w, err)
        return
    }

    userRequest := new(request.UserRequest)
    err = api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        api.ResponseError(w, err)
        return
    }

    userUpdate := userRequest.Transform(user)
    err = userUpdate.Update(u.Db)
    if err != nil {
        u.Log.Printf("error update user: %s", err)
        api.ResponseError(w, err)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(userUpdate)
    api.ResponseOK(w, resp, http.StatusOK)
}

// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        api.ResponseError(w, err)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        api.ResponseError(w, err)
        return
    }

    err = user.Delete(u.Db)
    if err != nil {
        u.Log.Println("Delete User", err)
        api.ResponseError(w, err)
        return
    }
    api.ResponseOK(w, nil, http.StatusNoContent)
}
```

