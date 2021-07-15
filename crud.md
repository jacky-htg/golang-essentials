# CRUD
Pada bab ini kita akan melengkapi method Users.Create, Users.View, Users.Update dan Users.Delete

## Create
- Karena membutuhkan beberapa validasi dan hashing password, create user akan ditangani menggunakan usecase.
- Pertama kita siapkan model-nya terlebih dahulu. Edit file models/user.go dan tambahkan method Create.
```
// Create new user
func (u *User) Create(db *sql.DB) error {
	const query = `
		INSERT INTO users (username, password, email, is_active, created, updated)
		VALUES (?, ?, ?, 0, NOW(), NOW())
	`
	stmt, err := db.Prepare(query)
	if err != nil {
		return err
	}

	defer stmt.Close()

	res, err := stmt.Exec(u.Username, u.Password, u.Email)
	if err != nil {
		return err
	}

	id, err := res.LastInsertId()
	if err != nil {
		return err
	}

	u.ID = uint64(id)

	return nil
}
```
- Untuk response kita sudah ada file payloads/response/user_response.go dan tidak perlu ada perubahan. Namun yang kita perlukan adalah payload untuk menangani request. Buatlah file payloads/request/user_request.go yang berfungsi untuk menerima json body dari request, kemudian mengconvertnya menjadi model.
```
package request

import (
	"essentials/models"
)

// NewUserRequest : format json request for new user
type NewUserRequest struct {
	Username   string `json:"username"`
	Email      string `json:"email"`
	Password   string `json:"password"`
	RePassword string `json:"re_password"`
}

// Transform NewUserRequest to User
func (u *NewUserRequest) Transform() *models.User {
	var user models.User
	user.Username = u.Username
	user.Email = u.Email
	user.Password = u.Password

	return &user
}

```

- Selanjutnya kita buat file usecases/user_usecase.go untuk interaksi dan validasi pembuatan user baru.
```
package usecases

import (
	"database/sql"
	"encoding/json"
	"errors"
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
func (u *UserUsecase) Create(r *http.Request) ([]byte, error) {
	var userRequest request.NewUserRequest
	var data []byte

	decoder := json.NewDecoder(r.Body)
	err := decoder.Decode(&userRequest)
	if err != nil {
		u.Log.Printf("error decode user: %s", err)
		return data, err
	}

	if userRequest.Password != userRequest.RePassword {
		err = errors.New("Password not match")
		u.Log.Printf("error : %s", err)
		return data, err
	}

	pass, err := bcrypt.GenerateFromPassword([]byte(userRequest.Password), bcrypt.DefaultCost)
	if err != nil {
		u.Log.Printf("error generate password: %s", err)
		return data, err
	}

	userRequest.Password = string(pass)

	user := userRequest.Transform()

	err = user.Create(u.Db)
	if err != nil {
		u.Log.Printf("error call create user: %s", err)
		return data, err
	}

	var res response.UserResponse
	res.Transform(user)
	data, err = json.Marshal(res)
	if err != nil {
		u.Log.Println("error marshalling result", err)
		return data, err
	}

	return data, nil
}
```

- Terakhir, ubah method Users.Create di file controllers/users.go
```
// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request) {
	uc := usecases.UserUsecase{Log: u.Log, Db: u.Db}
	data, err := uc.Create(r)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	if _, err := w.Write(data); err != nil {
		u.Log.Println("error writing result", err)
	}
}
```

## Read
- Tambahkan method `func (u *User) Get(db *sql.DB) error` pada file models/user.go
```
func (u *User) Get(db *sql.DB) error {
	const q = `SELECT id, username, password, email, is_active FROM users`
	return db.QueryRow(q+" WHERE id=?", u.ID).Scan(&u.ID, &u.Username, &u.Password, &u.Email, &u.IsActive)
}
```

- Ubah method View pada file controllers/users.go menjadi
```
// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request) {
	paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Println("convert param to id", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	user := new(models.User)
	user.ID = uint64(id)
	err = user.Get(u.Db)
	if err != nil {
		u.Log.Println("Get User", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	resp := new(response.UserResponse)
	resp.Transform(user)
	data, err := json.Marshal(resp)
	if err != nil {
		u.Log.Println("Marshall data user", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		u.Log.Println("error writing result", err)
	}
}
```

## Update
- Tambahkan method `func (u *User) Update(db *sql.DB) error` pada file models/user.go
```
// Update user by id
func (u *User) Update(db *sql.DB) error {
	const q string = `UPDATE users SET is_active = ? WHERE id = ?`
	stmt, err := db.Prepare(q)
	if err != nil {
		return err
	}

	defer stmt.Close()

	_, err = stmt.Exec(u.IsActive, u.ID)
	return err
}
```

- Tambahkan `type UserRequest struct{}` pada file payloads/request/user_request.go dan method Transform untuk reference UserRequest
```
// UserRequest : format json request for update user
type UserRequest struct {
	ID       uint64 `json:"id"`
	IsActive string `json:"is_active"`
}

// Transform UserRequest to User
func (u *UserRequest) Transform(user *models.User) *models.User {
	if u.ID == user.ID {
		if len(u.IsActive) > 0 {
			user.IsActive, _ = strconv.ParseBool(u.IsActive)
		}
	}

	return user
}
```

- Ubah method Update pada file controllers/users.go
```
paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Println("convert param to id", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	user := new(models.User)
	user.ID = uint64(id)
	err = user.Get(u.Db)
	if err != nil {
		u.Log.Println("Get User", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	userRequest := new(request.UserRequest)
	decoder := json.NewDecoder(r.Body)
	err = decoder.Decode(&userRequest)
	if err != nil {
		u.Log.Printf("error decode user: %s", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	userUpdate := userRequest.Transform(user)
	err = userUpdate.Update(u.Db)
	if err != nil {
		u.Log.Printf("error update user: %s", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	resp := new(response.UserResponse)
	resp.Transform(userUpdate)
	data, err := json.Marshal(resp)
	if err != nil {
		u.Log.Println("Marshall data user", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		u.Log.Println("error writing result", err)
	}
```

## Delete
- Tambahkan method `func (u *User) Delete(db *sql.DB) error` pada file models/user.go
```
// Delete user by id
func (u *User) Delete(db *sql.DB) error {
	const q string = `DELETE FROM users WHERE id = ?`
	stmt, err := db.Prepare(q)
	if err != nil {
		return err
	}

	defer stmt.Close()

	_, err = stmt.Exec(u.ID)
	return err
}
```

- Ubah method Delete pada file controllers/users.go
```
// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request) {
	paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Println("convert param to id", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	user := new(models.User)
	user.ID = uint64(id)
	err = user.Get(u.Db)
	if err != nil {
		u.Log.Println("Get User", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	err = user.Delete(u.Db)
	if err != nil {
		u.Log.Println("Delete User", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusNoContent)
}
```