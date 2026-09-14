FROM node:22-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5 AS build

ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME

WORKDIR /app

COPY 3d-shop-client/package.json 3d-shop-client/package-lock.json ./
RUN npm ci

COPY 3d-shop-client/ ./
RUN npm run build

# Served as a build rather than by the dev server: Vite transforms on demand in
# one process, and with three.js in the graph the first page load starves the
# proxied API calls until they time out.
FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10

COPY --from=build /app/build /usr/share/nginx/html
COPY docker/client-nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 3000
