package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

const defaultAssistAPIURL = "https://sistemax.pro/public/api/assist.php"

type assistState struct {
	AssistAPIURL    string `json:"assist_api_url"`
	DeviceID        int64  `json:"device_id"`
	DeviceUUID      string `json:"device_uuid"`
	DeviceName      string `json:"device_name"`
	HostName        string `json:"host_name"`
	Platform        string `json:"platform"`
	PlatformVersion string `json:"platform_version"`
	Architecture    string `json:"architecture"`
	AgentVersion    string `json:"agent_version"`
	AgentToken      string `json:"agent_token"`
	TokenExpiresAt  string `json:"token_expires_at"`
	LastHeartbeatAt string `json:"last_heartbeat_at"`
	LastError       string `json:"last_error"`
	UpdatedAt       string `json:"updated_at"`
}

type assistStatusResponse struct {
	OK         bool        `json:"ok"`
	Configured bool        `json:"configured"`
	Registered bool        `json:"registered"`
	State      assistState `json:"state"`
}

type assistRegisterRequest struct {
	BootstrapToken string `json:"bootstrap_token"`
	AssistAPIURL   string `json:"assist_api_url"`
	DeviceName     string `json:"device_name"`
}

type assistHeartbeatRequest struct {
	AssistAPIURL string `json:"assist_api_url"`
	Status       string `json:"status"`
}

type assistSyncRequest struct {
	BootstrapToken string `json:"bootstrap_token"`
	AssistAPIURL   string `json:"assist_api_url"`
	DeviceName     string `json:"device_name"`
	Status         string `json:"status"`
}

type assistAPIEnvelope struct {
	OK      bool            `json:"ok"`
	Error   string          `json:"error"`
	Message string          `json:"message"`
	Data    json.RawMessage `json:"data"`
}

type assistRegisterResult struct {
	DeviceID   int64  `json:"device_id"`
	AgentToken string `json:"agent_token"`
	ExpiresAt  string `json:"expires_at"`
}

func handleAssistStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	state, _ := assistLoadState()
	state = assistEnsureLocalMetadata(state, "")
	writeJSON(w, http.StatusOK, assistStatusResponse{
		OK:         true,
		Configured: strings.TrimSpace(state.AssistAPIURL) != "",
		Registered: state.DeviceID > 0 && strings.TrimSpace(state.AgentToken) != "",
		State:      state,
	})
}

func handleAssistRegister(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req assistRegisterRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "json invalido"})
		return
	}
	if strings.TrimSpace(req.BootstrapToken) == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "bootstrap_token requerido"})
		return
	}

	state, err := assistRegisterFlow(r.Context(), req.AssistAPIURL, req.BootstrapToken, req.DeviceName)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "data": state})
}

func handleAssistHeartbeat(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req assistHeartbeatRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil && err != io.EOF {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "json invalido"})
		return
	}

	state, err := assistHeartbeatFlow(r.Context(), req.AssistAPIURL, req.Status)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "data": state})
}

func handleAssistSync(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req assistSyncRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil && err != io.EOF {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "json invalido"})
		return
	}

	state, err := assistLoadState()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	state = assistEnsureLocalMetadata(state, req.DeviceName)
	if state.DeviceID <= 0 || strings.TrimSpace(state.AgentToken) == "" {
		if strings.TrimSpace(req.BootstrapToken) == "" {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "bootstrap_token requerido para el primer registro"})
			return
		}
		state, err = assistRegisterFlow(r.Context(), req.AssistAPIURL, req.BootstrapToken, req.DeviceName)
		if err != nil {
			writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
			return
		}
	}

	state, err = assistHeartbeatFlow(r.Context(), req.AssistAPIURL, req.Status)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "data": state})
}

func assistRegisterFlow(ctx context.Context, apiURL, bootstrapToken, deviceName string) (assistState, error) {
	state, err := assistLoadState()
	if err != nil {
		return assistState{}, err
	}
	state = assistEnsureLocalMetadata(state, deviceName)
	state.AssistAPIURL = assistResolveAPIURL(state.AssistAPIURL, apiURL)

	payload := map[string]any{
		"bootstrap_token":  strings.TrimSpace(bootstrapToken),
		"device_uuid":      state.DeviceUUID,
		"device_name":      state.DeviceName,
		"host_name":        state.HostName,
		"platform":         state.Platform,
		"platform_version": state.PlatformVersion,
		"architecture":     state.Architecture,
		"agent_version":    state.AgentVersion,
	}

	var result assistRegisterResult
	if err := assistPostJSON(ctx, state.AssistAPIURL, "agent_register_bootstrap", "", payload, &result); err != nil {
		state.LastError = err.Error()
		_ = assistSaveState(state)
		return state, err
	}

	state.DeviceID = result.DeviceID
	state.AgentToken = result.AgentToken
	state.TokenExpiresAt = result.ExpiresAt
	state.LastError = ""
	state.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	if err := assistSaveState(state); err != nil {
		return state, err
	}
	return state, nil
}

func assistHeartbeatFlow(ctx context.Context, apiURL, rawStatus string) (assistState, error) {
	state, err := assistLoadState()
	if err != nil {
		return assistState{}, err
	}
	state = assistEnsureLocalMetadata(state, "")
	state.AssistAPIURL = assistResolveAPIURL(state.AssistAPIURL, apiURL)
	if state.DeviceID <= 0 || strings.TrimSpace(state.AgentToken) == "" {
		return state, fmt.Errorf("el agente todavia no esta registrado")
	}

	status := strings.TrimSpace(rawStatus)
	if status == "" {
		status = "online"
	}

	payload := map[string]any{
		"status":       status,
		"ip_local":     assistLocalIPv4(),
		"capabilities": assistCapabilitiesSnapshot(ctx),
	}
	var heartbeatResult map[string]any
	if err := assistPostJSON(ctx, state.AssistAPIURL, "agent_heartbeat", state.AgentToken, payload, &heartbeatResult); err != nil {
		state.LastError = err.Error()
		state.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
		_ = assistSaveState(state)
		return state, err
	}

	now := time.Now().UTC().Format(time.RFC3339)
	state.LastHeartbeatAt = now
	state.LastError = ""
	state.UpdatedAt = now
	if err := assistSaveState(state); err != nil {
		return state, err
	}
	return state, nil
}

func assistCapabilitiesSnapshot(ctx context.Context) map[string]any {
	permissions := getPermissionsStatus()
	return map[string]any{
		"can_screen_capture":           true,
		"can_input_control":            true,
		"can_file_transfer":            false,
		"can_clipboard_sync":           false,
		"can_audio_stream":             false,
		"can_unattended":               false,
		"requires_local_consent":       true,
		"permissions_screen":           permissions.ScreenRecording,
		"permissions_accessibility":    permissions.Accessibility,
		"permissions_input_monitoring": false,
		"native_agent":                 true,
		"native_control_mode":          "assist_mvp",
	}
}

func assistPostJSON(ctx context.Context, apiURL, action, bearer string, payload any, out any) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, assistResolveAPIURL("", apiURL)+"?action="+action, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	if strings.TrimSpace(bearer) != "" {
		req.Header.Set("Authorization", "Bearer "+strings.TrimSpace(bearer))
	}

	client := &http.Client{Timeout: 20 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	var envelope assistAPIEnvelope
	if err := json.NewDecoder(resp.Body).Decode(&envelope); err != nil {
		return fmt.Errorf("respuesta invalida del servidor")
	}
	if resp.StatusCode >= 400 || !envelope.OK {
		msg := strings.TrimSpace(envelope.Error)
		if msg == "" {
			msg = strings.TrimSpace(envelope.Message)
		}
		if msg == "" {
			msg = "error remoto"
		}
		return fmt.Errorf(msg)
	}
	if out == nil || len(envelope.Data) == 0 {
		return nil
	}
	return json.Unmarshal(envelope.Data, out)
}

func assistResolveAPIURL(current, override string) string {
	if strings.TrimSpace(override) != "" {
		return strings.TrimSpace(override)
	}
	if strings.TrimSpace(current) != "" {
		return strings.TrimSpace(current)
	}
	return defaultAssistAPIURL
}

func assistEnsureLocalMetadata(state assistState, requestedName string) assistState {
	hostName, _ := os.Hostname()
	if state.DeviceUUID == "" {
		state.DeviceUUID = assistRandomUUID()
	}
	if strings.TrimSpace(requestedName) != "" {
		state.DeviceName = strings.TrimSpace(requestedName)
	}
	if state.DeviceName == "" {
		state.DeviceName = hostName
	}
	if state.HostName == "" {
		state.HostName = hostName
	}
	state.Platform = runtime.GOOS
	state.Architecture = runtime.GOARCH
	state.AgentVersion = version
	if state.PlatformVersion == "" {
		state.PlatformVersion = runtime.GOOS
	}
	if state.AssistAPIURL == "" {
		state.AssistAPIURL = defaultAssistAPIURL
	}
	return state
}

func assistLocalIPv4() string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return ""
	}
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			var ip net.IP
			switch v := addr.(type) {
			case *net.IPNet:
				ip = v.IP
			case *net.IPAddr:
				ip = v.IP
			}
			if ip == nil {
				continue
			}
			ip = ip.To4()
			if ip != nil && !ip.IsLoopback() {
				return ip.String()
			}
		}
	}
	return ""
}

func assistLoadState() (assistState, error) {
	path, err := assistStatePath()
	if err != nil {
		return assistState{}, err
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return assistState{}, nil
		}
		return assistState{}, err
	}
	var state assistState
	if err := json.Unmarshal(raw, &state); err != nil {
		return assistState{}, err
	}
	return state, nil
}

func assistSaveState(state assistState) error {
	path, err := assistStatePath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	state.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	raw, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, raw, 0o600)
}

func assistStatePath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, ".sistemax-agent", "assist.json"), nil
}

func assistRandomUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		now := time.Now().UnixNano()
		return fmt.Sprintf("fallback-%d", now)
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	hexStr := hex.EncodeToString(b[:])
	return fmt.Sprintf("%s-%s-%s-%s-%s", hexStr[0:8], hexStr[8:12], hexStr[12:16], hexStr[16:20], hexStr[20:32])
}
