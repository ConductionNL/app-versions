# Makefile for app-versions development
#
# Nextcloud loads apps by the directory name, which must match the <id> in
# appinfo/info.xml. This repo is cloned as `app-versions` but the app id is
# `app_versions` (snake_case per Nextcloud conventions). Create a relative
# symlink in the parent directory so Nextcloud can find the app.
dev-link:
	@if [ -L ../app_versions ]; then \
		echo "Symlink ../app_versions already exists."; \
	else \
		ln -s app-versions ../app_versions && \
		echo "Created symlink: apps-extra/app_versions -> app-versions"; \
	fi

dev-unlink:
	@if [ -L ../app_versions ]; then \
		rm ../app_versions && echo "Removed symlink ../app_versions"; \
	else \
		echo "No symlink found at ../app_versions."; \
	fi

.PHONY: dev-link dev-unlink
