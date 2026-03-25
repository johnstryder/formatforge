.PHONY: build
build:
	go build -o pipeline-generate .
	chmod +x pipeline-generate

.PHONY: run
run: build
	./pipeline-generate

.PHONY: deps
deps:
	go mod tidy
