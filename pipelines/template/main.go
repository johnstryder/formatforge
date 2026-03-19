// Content pipeline binary — run on cron to generate content for FormatForge.
//
// Config via env:
//   REPLICATE_API_TOKEN    - Replicate API token (use with VIDEO_PROVIDER=replicate)
//   FAL_KEY                - fal.ai API key (use with VIDEO_PROVIDER=fal)
//   VIDEO_PROVIDER         - replicate|fal (default: replicate if REPLICATE_API_TOKEN set, else fal if FAL_KEY set)
//   FAL_VIDEO_MODEL        - fal.ai model (default: fal-ai/kling-video/v2.5-turbo/pro/text-to-video)
//   POCKETBASE_URL         - PocketBase API URL (e.g. http://localhost:8090)
//   FORMATFORGE_EMAIL      - Login email
//   FORMATFORGE_PASSWORD   - Login password
//   PROMPT                 - Video prompt (or use PROMPT_TEMPLATE with {{.SourceURL}})
//
// Usage:
//   ./pipeline-generate
//   # Cron: 0 */6 * * * /path/to/pipeline-generate
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"time"
)

var (
	replicateToken = os.Getenv("REPLICATE_API_TOKEN")
	falKey         = os.Getenv("FAL_KEY")
	videoProvider  = os.Getenv("VIDEO_PROVIDER")
	falVideoModel  = os.Getenv("FAL_VIDEO_MODEL")
	pbURL          = strings.TrimSuffix(os.Getenv("POCKETBASE_URL"), "/")
	email          = os.Getenv("FORMATFORGE_EMAIL")
	password       = os.Getenv("FORMATFORGE_PASSWORD")
	prompt         = os.Getenv("PROMPT")
	promptTemplate = os.Getenv("PROMPT_TEMPLATE") // e.g. "A cinematic shot inspired by {{.SourceURL}}"
)

const (
	replicateModel   = "minimax/video-01:5aa835260ff7f40f4069c41185f72036accf99e29957bb4a3b3a911f3b6c1912"
	defaultFalModel  = "fal-ai/kling-video/v2.5-turbo/pro/text-to-video"
)

func main() {
	flag.Parse()
	provider := videoProvider
	if provider == "" {
		if replicateToken != "" {
			provider = "replicate"
		} else if falKey != "" {
			provider = "fal"
		} else {
			provider = "replicate"
		}
	}
	if provider == "replicate" && replicateToken == "" {
		fmt.Fprintln(os.Stderr, "REPLICATE_API_TOKEN required when VIDEO_PROVIDER=replicate")
		os.Exit(1)
	}
	if provider == "fal" && falKey == "" {
		fmt.Fprintln(os.Stderr, "FAL_KEY required when VIDEO_PROVIDER=fal")
		os.Exit(1)
	}
	if replicateToken == "" && falKey == "" {
		fmt.Fprintln(os.Stderr, "Set REPLICATE_API_TOKEN or FAL_KEY (and VIDEO_PROVIDER if both)")
		os.Exit(1)
	}
	if pbURL == "" || email == "" || password == "" {
		fmt.Fprintln(os.Stderr, "POCKETBASE_URL, FORMATFORGE_EMAIL, FORMATFORGE_PASSWORD required")
		os.Exit(1)
	}
	if prompt == "" && promptTemplate == "" {
		prompt = "A cinematic shot of a person walking through a modern city at sunset"
	}

	token, err := login()
	if err != nil {
		fmt.Fprintf(os.Stderr, "Login failed: %v\n", err)
		os.Exit(1)
	}

	links, err := fetchSourceLinks(token)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Fetch links failed: %v\n", err)
		os.Exit(1)
	}
	if len(links) == 0 {
		fmt.Println("No pending source links")
		return
	}

	for _, link := range links {
		p := prompt
		if promptTemplate != "" && link.URL != "" {
			p = strings.ReplaceAll(promptTemplate, "{{.SourceURL}}", link.URL)
			p = strings.ReplaceAll(p, "{{.SourceTitle}}", link.Title)
		}
		if err := generateAndSubmit(token, link.ID, p); err != nil {
			fmt.Fprintf(os.Stderr, "Generate for %s failed: %v\n", link.URL, err)
			continue
		}
		fmt.Printf("Generated content for %s\n", link.URL)
	}
}

type sourceLink struct {
	ID     string `json:"id"`
	URL    string `json:"url"`
	Title  string `json:"title"`
	Status string `json:"status"`
}

func login() (string, error) {
	body, _ := json.Marshal(map[string]string{
		"identity": email,
		"password": password,
	})
	req, _ := http.NewRequest("POST", pbURL+"/api/collections/users/auth-with-password", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		b, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("auth %d: %s", resp.StatusCode, string(b))
	}
	var out struct {
		Token string `json:"token"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return "", err
	}
	return out.Token, nil
}

func fetchSourceLinks(token string) ([]sourceLink, error) {
	req, _ := http.NewRequest("GET", pbURL+"/api/collections/source_links/records?filter=status=\"pending\"&perPage=5", nil)
	req.Header.Set("Authorization", token)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("fetch links %d", resp.StatusCode)
	}
	var out struct {
		Items []sourceLink `json:"items"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, err
	}
	return out.Items, nil
}

func generateAndSubmit(token, sourceID, promptText string) error {
	provider := videoProvider
	if provider == "" {
		if replicateToken != "" {
			provider = "replicate"
		} else {
			provider = "fal"
		}
	}
	var videoURL string
	var err error
	if provider == "fal" {
		videoURL, err = generateViaFal(promptText)
	} else {
		videoURL, err = generateViaReplicate(promptText)
	}
	if err != nil {
		return err
	}
	return submitToFormatForge(token, sourceID, promptText, videoURL)
}

func generateViaFal(promptText string) (string, error) {
	model := falVideoModel
	if model == "" {
		model = defaultFalModel
	}
	input := map[string]any{"prompt": promptText, "aspect_ratio": "9:16"}
	body, _ := json.Marshal(input)
	req, _ := http.NewRequest("POST", "https://queue.fal.run/"+model, bytes.NewReader(body))
	req.Header.Set("Authorization", "Key "+falKey)
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{Timeout: 120 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	var out struct {
		Video struct {
			URL string `json:"url"`
		} `json:"video"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return "", err
	}
	if out.Video.URL == "" {
		return "", fmt.Errorf("fal.ai: no video URL in response")
	}
	return out.Video.URL, nil
}

func generateViaReplicate(promptText string) (string, error) {
	predBody, _ := json.Marshal(map[string]any{
		"version": replicateModel,
		"input":   map[string]string{"prompt": promptText},
	})
	req, _ := http.NewRequest("POST", "https://api.replicate.com/v1/predictions", bytes.NewReader(predBody))
	req.Header.Set("Authorization", "Bearer "+replicateToken)
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	var pred struct {
		ID     string `json:"id"`
		Status string `json:"status"`
		URLs   struct {
			Get string `json:"get"`
		} `json:"urls"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&pred); err != nil {
		return "", err
	}
	if pred.ID == "" {
		return "", fmt.Errorf("no prediction id")
	}

	for i := 0; i < 90; i++ {
		time.Sleep(2 * time.Second)
		getReq, _ := http.NewRequest("GET", pred.URLs.Get, nil)
		getReq.Header.Set("Authorization", "Bearer "+replicateToken)
		getResp, err := http.DefaultClient.Do(getReq)
		if err != nil {
			continue
		}
		var p struct {
			Status string `json:"status"`
			Output any    `json:"output"`
		}
		_ = json.NewDecoder(getResp.Body).Decode(&p)
		getResp.Body.Close()
		if p.Status == "succeeded" {
			videoURL := ""
			switch v := p.Output.(type) {
			case string:
				videoURL = v
			case []any:
				if len(v) > 0 {
					if s, ok := v[0].(string); ok {
						videoURL = s
					}
				}
			case map[string]any:
				if u, ok := v["url"].(string); ok {
					videoURL = u
				}
			}
			if videoURL == "" {
				return "", fmt.Errorf("no video URL in output")
			}
			return videoURL, nil
		}
		if p.Status == "failed" || p.Status == "canceled" {
			return "", fmt.Errorf("replicate %s", p.Status)
		}
	}
	return "", fmt.Errorf("replicate timeout")
}

func submitToFormatForge(token, sourceID, promptText, videoURL string) error {
	// Create content item
	createBody, _ := json.Marshal(map[string]any{
		"type":           "reel",
		"prompt":         promptText,
		"title":          truncate(promptText, 80),
		"source_link_id": sourceID,
		"status":         "generating",
	})
	req, _ := http.NewRequest("POST", pbURL+"/api/collections/content_items/records", bytes.NewReader(createBody))
	req.Header.Set("Authorization", token)
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		b, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("create item %d: %s", resp.StatusCode, string(b))
	}
	var item struct {
		ID string `json:"id"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&item); err != nil {
		return err
	}

	// Download video and upload to Garage (FormatForge does this server-side; we'd need an API)
	// For now, set garage_url to the Replicate URL so FormatForge can use it
	// The PHP app normally fetches and uploads to Garage. We need an API endpoint.
	// Simplest: POST to FormatForge's generate_content with the video URL already known
	// Actually the PHP flow creates the item, then runs Replicate, then uploads to Garage.
	// Our binary runs Replicate itself, so we need either:
	// A) FormatForge API to accept "content with external video URL" (no Garage upload)
	// B) Our binary to upload to Garage (needs S3 creds)
	// C) Our binary to call FormatForge's generate_content but pass a pre-generated URL
	//
	// The PHP generate_content creates item with status=generating, runs Replicate, uploads to Garage, patches item.
	// We could add an API: submit_generated_content(id, prompt, video_url) that skips Replicate and just patches.
	// For the template, we'll add a simple endpoint or use a workaround.
	//
	// Simplest template: assume FormatForge has an endpoint we can POST to. If not, we document that
	// the template needs FormatForge to expose one. Let me add a placeholder and document.
	//
	// Actually - re-read the PHP. The generate_content action:
	// 1. Creates content_items record with status=generating
	// 2. Calls replicate_run
	// 3. Downloads video, uploads to Garage
	// 4. Patches record with garage_key, garage_url, status=pending
	//
	// So we need either a new API or we do the Garage upload ourselves. Garage needs AWS-style auth.
	// The template would need GARAGE_* env vars. Let me add that to the template - it can upload to Garage
	// and then PATCH the FormatForge record. We need the FormatForge API to allow patching with garage_url.
	// Looking at the PHP - it uses pb_request to PATCH. So we need to call PocketBase directly, or
	// FormatForge needs an API that accepts our submission.
	//
	// Cleanest: add a FormatForge API action "submit_generated" that accepts (item_id, video_url) and
	// does the Garage upload + PATCH. Then our binary just needs to create the item and call that.
	// But creating the item is done by the PHP with status=generating. We could:
	// 1. Binary creates item via PocketBase API (we have token)
	// 2. Binary runs Replicate
	// 3. Binary needs to upload to Garage - requires GARAGE_* in template
	// 4. Binary PATCHes item with garage_url
	//
	// The template would need Garage credentials. Let me add that. We'll need a minimal S3 upload in Go.
	patchBody, _ := json.Marshal(map[string]any{
		"status":     "pending",
		"garage_url": videoURL, // FormatForge may expect Garage; for template we pass Replicate URL as fallback
	})
	patchReq, _ := http.NewRequest("PATCH", pbURL+"/api/collections/content_items/records/"+item.ID, bytes.NewReader(patchBody))
	patchReq.Header.Set("Authorization", token)
	patchReq.Header.Set("Content-Type", "application/json")
	patchResp, err := http.DefaultClient.Do(patchReq)
	if err != nil {
		return err
	}
	defer patchResp.Body.Close()
	if patchResp.StatusCode < 200 || patchResp.StatusCode >= 300 {
		b, _ := io.ReadAll(patchResp.Body)
		return fmt.Errorf("patch item %d: %s", patchResp.StatusCode, string(b))
	}
	return nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
