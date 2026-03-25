// pipeline-generate — FormatForge pipeline worker (modal + cron).
//
// When PIPELINE_PB_ID or FORMATFORGE_RUN_PIPELINE_ID is set (FormatForge spawns this):
//   1) Auth to PocketBase (users collection).
//   2) List content_items with status=generating and metadata.pipeline_id matching.
//   3) For each row: this **template** uses ffmpeg for a minimal path; real pipelines usually call **Replicate/fal**
//      (models chosen per README — replicate.com run counts) then optional mux/post in ffmpeg. Garage S3 PUT,
//      multipart PATCH with media_file + pending.
//
// Env (pipeline .env + FormatForge merge): POCKETBASE_URL, FORMATFORGE_EMAIL, FORMATFORGE_PASSWORD,
// OUTPUT_MODE (image|video|reel|carousel), GARAGE_* optional, FONT_PATH optional, FFMPEG_PATH optional.
package main

import (
	"bytes"
	crand "crypto/rand"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

func getenv(k, def string) string {
	v := strings.TrimSpace(os.Getenv(k))
	if v == "" {
		return def
	}
	return v
}

func pipelineID() string {
	if v := strings.TrimSpace(os.Getenv("PIPELINE_PB_ID")); v != "" {
		return v
	}
	return strings.TrimSpace(os.Getenv("FORMATFORGE_RUN_PIPELINE_ID"))
}

func pbBase() string {
	return strings.TrimSuffix(getenv("POCKETBASE_URL", ""), "/")
}

func login(email, password string) (string, error) {
	body, _ := json.Marshal(map[string]string{
		"identity": email,
		"password": password,
	})
	req, err := http.NewRequest(http.MethodPost, pbBase()+"/api/collections/users/auth-with-password", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("auth %d: %s", resp.StatusCode, string(b))
	}
	var out struct {
		Token string `json:"token"`
	}
	if err := json.Unmarshal(b, &out); err != nil {
		return "", err
	}
	if out.Token == "" {
		return "", fmt.Errorf("auth: empty token")
	}
	return out.Token, nil
}

type contentItem struct {
	ID             string         `json:"id"`
	CollectionID   string         `json:"collectionId"`
	Prompt         string         `json:"prompt"`
	Type           string         `json:"type"`
	Status         string         `json:"status"`
	Title          string         `json:"title"`
	MediaFile      string         `json:"media_file"`
	Metadata       map[string]any `json:"metadata"`
	GarageKey      string         `json:"garage_key"`
	GarageURL      string         `json:"garage_url"`
}

func fetchGeneratingItems(token, wantPipeline string) ([]contentItem, error) {
	// Avoid nested JSON filter quirks: pull recent generating rows and filter in-process.
	q := url.Values{}
	q.Set("perPage", "100")
	q.Set("sort", "-@rowid")
	q.Set("filter", `status="generating"`)
	req, err := http.NewRequest(http.MethodGet, pbBase()+"/api/collections/content_items/records?"+q.Encode(), nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", token)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("list content_items %d: %s", resp.StatusCode, string(b))
	}
	var out struct {
		Items []contentItem `json:"items"`
	}
	if err := json.Unmarshal(b, &out); err != nil {
		return nil, err
	}
	var match []contentItem
	for _, it := range out.Items {
		meta := it.Metadata
		if meta == nil {
			continue
		}
		pid, _ := meta["pipeline_id"].(string)
		if strings.TrimSpace(pid) == wantPipeline {
			match = append(match, it)
		}
	}
	return match, nil
}

func parseHeadlineBody(prompt string) (headline, body string) {
	lines := strings.Split(strings.ReplaceAll(prompt, "\r\n", "\n"), "\n")
	var nonEmpty []string
	for _, ln := range lines {
		s := strings.TrimSpace(ln)
		if s != "" && !strings.HasPrefix(s, "---") {
			nonEmpty = append(nonEmpty, s)
		}
	}
	if len(nonEmpty) == 0 {
		return "FormatForge pipeline", "Original composition aligned to source intent."
	}
	headline = nonEmpty[0]
	if len(nonEmpty) > 1 {
		body = strings.Join(nonEmpty[1:], "\n")
	} else {
		body = "Distilled layout + typography — not a copy of backing media."
	}
	if len(headline) > 72 {
		headline = headline[:72] + "…"
	}
	return headline, body
}

func fontPath() string {
	if p := getenv("FONT_PATH", ""); p != "" {
		return p
	}
	candidates := []string{
		"/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
		"/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
		"/usr/share/fonts/TTF/DejaVuSans.ttf",
	}
	for _, c := range candidates {
		if st, err := os.Stat(c); err == nil && !st.IsDir() {
			return c
		}
	}
	return ""
}

func ffmpegBin() string {
	return getenv("FFMPEG_PATH", "ffmpeg")
}

func writeTextFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o600)
}

func renderImage(headline, body, outPath string) error {
	dir := filepath.Dir(outPath)
	headF := filepath.Join(dir, "ff_head_"+randHex(6)+".txt")
	bodyF := filepath.Join(dir, "ff_body_"+randHex(6)+".txt")
	defer os.Remove(headF)
	defer os.Remove(bodyF)
	if err := writeTextFile(headF, headline); err != nil {
		return err
	}
	if err := writeTextFile(bodyF, body); err != nil {
		return err
	}
	fp := fontPath()
	if fp == "" {
		return fmt.Errorf("no font found; set FONT_PATH to a .ttf (e.g. DejaVuSans.ttf)")
	}
	// 1080×1350 portrait card; dark plate + two drawtext blocks (no source pixels).
	vf := fmt.Sprintf(
		"drawtext=fontfile=%s:textfile=%s:fontsize=52:fontcolor=white:x=(w-text_w)/2:y=120:line_spacing=16,"+
			"drawtext=fontfile=%s:textfile=%s:fontsize=30:fontcolor=0xcccccc:x=80:y=280:line_spacing=14:box=1:boxcolor=black@0.35:boxborderw=24",
		escapePathForFilter(fp), escapePathForFilter(headF),
		escapePathForFilter(fp), escapePathForFilter(bodyF),
	)
	args := []string{
		"-y", "-f", "lavfi", "-i", "color=c=0x16213e:s=1080x1350:d=1",
		"-vf", vf,
		"-frames:v", "1", "-q:v", "2",
		outPath,
	}
	cmd := exec.Command(ffmpegBin(), args...)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("ffmpeg image: %w: %s", err, stderr.String())
	}
	return nil
}

func renderVideo(headline, body, outPath string) error {
	dir := filepath.Dir(outPath)
	headF := filepath.Join(dir, "ff_vhead_"+randHex(6)+".txt")
	bodyF := filepath.Join(dir, "ff_vbody_"+randHex(6)+".txt")
	defer os.Remove(headF)
	defer os.Remove(bodyF)
	if err := writeTextFile(headF, headline); err != nil {
		return err
	}
	if err := writeTextFile(bodyF, body); err != nil {
		return err
	}
	fp := fontPath()
	if fp == "" {
		return fmt.Errorf("no font found; set FONT_PATH to a .ttf")
	}
	vf := fmt.Sprintf(
		"drawtext=fontfile=%s:textfile=%s:fontsize=48:fontcolor=white:x=(w-text_w)/2:y=160:line_spacing=14,"+
			"drawtext=fontfile=%s:textfile=%s:fontsize=28:fontcolor=0xdddddd:x=72:y=360:line_spacing=12:box=1:boxcolor=black@0.4:boxborderw=20",
		escapePathForFilter(fp), escapePathForFilter(headF),
		escapePathForFilter(fp), escapePathForFilter(bodyF),
	)
	// 9:16 style frame; silent AAC for container compatibility.
	args := []string{
		"-y",
		"-f", "lavfi", "-i", "color=c=0x0f0f23:s=1080x1920:r=30",
		"-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
		"-vf", vf,
		"-t", "8",
		"-c:v", "libx264", "-pix_fmt", "yuv420p", "-profile:v", "high", "-movflags", "+faststart",
		"-c:a", "aac", "-b:a", "128k",
		"-shortest",
		outPath,
	}
	cmd := exec.Command(ffmpegBin(), args...)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("ffmpeg video: %w: %s", err, stderr.String())
	}
	return nil
}

// ffmpeg filtergraph path escaping: replace \ and : and '
func escapePathForFilter(p string) string {
	p = strings.ReplaceAll(p, `\`, `\\`)
	p = strings.ReplaceAll(p, `:`, `\:`)
	p = strings.ReplaceAll(p, `'`, `\'`)
	return p
}

func randHex(n int) string {
	nb := (n + 1) / 2
	b := make([]byte, nb)
	if _, err := crand.Read(b); err != nil {
		return fmt.Sprintf("%x", time.Now().UnixNano())[:n]
	}
	s := hex.EncodeToString(b)
	if len(s) >= n {
		return s[:n]
	}
	return s
}

func sha256hex(b []byte) string {
	h := sha256.Sum256(b)
	return hex.EncodeToString(h[:])
}

func hmacSHA256(key []byte, data string) []byte {
	m := hmac.New(sha256.New, key)
	m.Write([]byte(data))
	return m.Sum(nil)
}

func garageSigV4Put(endpoint, bucket, region, accessKey, secretKey, objectKey string, payload []byte, contentType string) error {
	u, err := url.Parse(endpoint)
	if err != nil {
		return err
	}
	host := u.Host
	path := "/" + bucket + "/" + strings.TrimPrefix(objectKey, "/")
	putURL := strings.TrimSuffix(endpoint, "/") + path
	now := time.Now().UTC()
	amzDate := now.Format("20060102T150405Z")
	dateStamp := now.Format("20060102")
	payloadHash := sha256hex(payload)
	canonicalHeaders := fmt.Sprintf("content-type:%s\nhost:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n",
		contentType, host, payloadHash, amzDate)
	signedHeaders := "content-type;host;x-amz-content-sha256;x-amz-date"
	canonicalRequest := fmt.Sprintf("PUT\n%s\n\n%s\n%s\n%s", path, canonicalHeaders, signedHeaders, payloadHash)
	credentialScope := fmt.Sprintf("%s/%s/s3/aws4_request", dateStamp, region)
	stringToSign := fmt.Sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", amzDate, credentialScope, sha256hex([]byte(canonicalRequest)))
	kDate := hmacSHA256([]byte("AWS4"+secretKey), dateStamp)
	kRegion := hmacSHA256(kDate, region)
	kService := hmacSHA256(kRegion, "s3")
	kSigning := hmacSHA256(kService, "aws4_request")
	signature := hex.EncodeToString(hmacSHA256(kSigning, stringToSign))
	auth := fmt.Sprintf("AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s",
		accessKey, credentialScope, signedHeaders, signature)

	req, err := http.NewRequest(http.MethodPut, putURL, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", contentType)
	req.Header.Set("Host", host)
	req.Header.Set("x-amz-content-sha256", payloadHash)
	req.Header.Set("x-amz-date", amzDate)
	req.Header.Set("Authorization", auth)

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("garage PUT %d: %s", resp.StatusCode, string(rb))
	}
	return nil
}

func garagePublicObjectURL(bucket, endpoint, publicBase, objectKey string) string {
	keyPart := url.PathEscape(strings.TrimPrefix(objectKey, "/"))
	// replicate PHP garage_public_url_for_key virtual-host branch
	pub := strings.TrimSuffix(strings.TrimSpace(publicBase), "/")
	if pub == "" {
		return strings.TrimSuffix(endpoint, "/") + "/" + bucket + "/" + strings.ReplaceAll(objectKey, "%2F", "/")
	}
	u, err := url.Parse(pub)
	if err != nil {
		return pub + "/" + keyPart
	}
	host := strings.ToLower(u.Host)
	vh := strings.ToLower(bucket) + ".web."
	if strings.HasPrefix(host, vh) {
		return pub + "/" + strings.ReplaceAll(objectKey, "%2F", "/")
	}
	return pub + "/" + bucket + "/" + strings.ReplaceAll(objectKey, "%2F", "/")
}

func pbPublicFileURL(collectionID, recordID, filename string) string {
	base := strings.TrimSuffix(getenv("POCKETBASE_PUBLIC_URL", getenv("POCKETBASE_URL", "")), "/")
	if base == "" || collectionID == "" || recordID == "" || filename == "" {
		return ""
	}
	// /api/files/{collection}/{record}/{filename}
	return fmt.Sprintf("%s/api/files/%s/%s/%s",
		base, url.PathEscape(collectionID), url.PathEscape(recordID), url.PathEscape(filename))
}

func patchMultipart(token, itemID string, fields map[string]string, fileField, filePath, fileMIME string) (contentItem, error) {
	var buf bytes.Buffer
	w := multipart.NewWriter(&buf)
	for k, v := range fields {
		_ = w.WriteField(k, v)
	}
	fh, err := os.Open(filePath)
	if err != nil {
		return contentItem{}, err
	}
	defer fh.Close()
	part, err := w.CreateFormFile(fileField, filepath.Base(filePath))
	if err != nil {
		return contentItem{}, err
	}
	if _, err := io.Copy(part, fh); err != nil {
		return contentItem{}, err
	}
	if err := w.Close(); err != nil {
		return contentItem{}, err
	}
	req, err := http.NewRequest(http.MethodPatch, pbBase()+"/api/collections/content_items/records/"+url.PathEscape(itemID), &buf)
	if err != nil {
		return contentItem{}, err
	}
	req.Header.Set("Content-Type", w.FormDataContentType())
	req.Header.Set("Authorization", token)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return contentItem{}, err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return contentItem{}, fmt.Errorf("multipart patch %d: %s", resp.StatusCode, string(b))
	}
	var out contentItem
	if err := json.Unmarshal(b, &out); err != nil {
		return contentItem{}, err
	}
	return out, nil
}

func patchJSON(token, itemID string, payload map[string]any) error {
	body, _ := json.Marshal(payload)
	req, err := http.NewRequest(http.MethodPatch, pbBase()+"/api/collections/content_items/records/"+url.PathEscape(itemID), bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", token)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("json patch %d: %s", resp.StatusCode, string(b))
	}
	return nil
}

func failItem(token, itemID, msg string) {
	_ = patchJSON(token, itemID, map[string]any{
		"status":           "failed",
		"rejected_reason":  truncateStr(msg, 500),
		"garage_key":       "",
		"garage_url":       "",
	})
}

func truncateStr(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

func outputModeNormalized() string {
	m := strings.ToLower(strings.TrimSpace(getenv("OUTPUT_MODE", "image")))
	if m == "carousel" {
		return "image"
	}
	return m
}

func processItem(token string, it contentItem) error {
	mode := outputModeNormalized()
	head, body := parseHeadlineBody(it.Prompt)

	dir, err := os.MkdirTemp("", "ffpipe_*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)

	var mediaPath, mime, ext string
	switch mode {
	case "image":
		mediaPath = filepath.Join(dir, "out.jpg")
		mime = "image/jpeg"
		ext = "jpg"
		if err := renderImage(head, body, mediaPath); err != nil {
			return err
		}
	case "video", "reel":
		mediaPath = filepath.Join(dir, "out.mp4")
		mime = "video/mp4"
		ext = "mp4"
		if err := renderVideo(head, body, mediaPath); err != nil {
			return err
		}
	default:
		return fmt.Errorf("unsupported OUTPUT_MODE %q", mode)
	}

	bin, err := os.ReadFile(mediaPath)
	if err != nil {
		return err
	}

	garageKey := fmt.Sprintf("content/%s/%s.%s", it.ID, time.Now().UTC().Format("20060102150405"), ext)
	ak := strings.TrimSpace(os.Getenv("GARAGE_ACCESS_KEY"))
	sk := strings.TrimSpace(os.Getenv("GARAGE_SECRET_KEY"))
	ep := strings.TrimSuffix(getenv("GARAGE_ENDPOINT", ""), "/")
	bucket := getenv("GARAGE_BUCKET", "formatforge")
	region := getenv("GARAGE_REGION", "garage")
	pub := getenv("GARAGE_PUBLIC_URL", "")

	fields := map[string]string{
		"status":     "pending",
		"garage_url": "",
	}
	if ak != "" && sk != "" && ep != "" {
		if err := garageSigV4Put(ep, bucket, region, ak, sk, garageKey, bin, mime); err != nil {
			return fmt.Errorf("garage upload: %w", err)
		}
		fields["garage_key"] = garageKey
		gu := garagePublicObjectURL(bucket, ep, pub, garageKey)
		if gu != "" {
			fields["garage_url"] = gu
		}
	} else {
		fields["garage_key"] = garageKey
	}

	updated, err := patchMultipart(token, it.ID, fields, "media_file", mediaPath, mime)
	if err != nil {
		return err
	}

	coll := strings.TrimSpace(updated.CollectionID)
	fn := strings.TrimSpace(updated.MediaFile)
	if coll == "" || fn == "" {
		// Re-fetch record
		req, _ := http.NewRequest(http.MethodGet, pbBase()+"/api/collections/content_items/records/"+url.PathEscape(it.ID), nil)
		req.Header.Set("Authorization", token)
		resp, err := http.DefaultClient.Do(req)
		if err == nil && resp.StatusCode == http.StatusOK {
			var full contentItem
			_ = json.NewDecoder(resp.Body).Decode(&full)
			resp.Body.Close()
			coll = strings.TrimSpace(full.CollectionID)
			fn = strings.TrimSpace(full.MediaFile)
		}
	}
	pubMedia := pbPublicFileURL(coll, it.ID, fn)
	if pubMedia != "" {
		_ = patchJSON(token, it.ID, map[string]any{"garage_url": pubMedia})
	}

	fmt.Printf("Completed pipeline item %s\n", it.ID)
	return nil
}

func main() {
	email := strings.TrimSpace(os.Getenv("FORMATFORGE_EMAIL"))
	pass := strings.TrimSpace(os.Getenv("FORMATFORGE_PASSWORD"))
	if pbBase() == "" || email == "" || pass == "" {
		fmt.Fprintln(os.Stderr, "POCKETBASE_URL, FORMATFORGE_EMAIL, FORMATFORGE_PASSWORD required")
		os.Exit(1)
	}

	pid := pipelineID()
	if pid == "" {
		fmt.Fprintln(os.Stderr, "PIPELINE_PB_ID (or FORMATFORGE_RUN_PIPELINE_ID) required for FormatForge pipeline runs")
		os.Exit(1)
	}

	token, err := login(email, pass)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Login failed: %v\n", err)
		os.Exit(1)
	}

	items, err := fetchGeneratingItems(token, pid)
	if err != nil {
		fmt.Fprintf(os.Stderr, "List items failed: %v\n", err)
		os.Exit(1)
	}
	if len(items) == 0 {
		fmt.Println("No generating content_items for this pipeline")
		return
	}

	for _, it := range items {
		if err := processItem(token, it); err != nil {
			fmt.Fprintf(os.Stderr, "Item %s: %v\n", it.ID, err)
			failItem(token, it.ID, fmt.Sprintf("[pipeline] %v", err))
		}
	}
}
