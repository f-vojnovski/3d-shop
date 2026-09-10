ARG THREE_VERSION=0.186.0

FROM node:22-slim AS three
ARG THREE_VERSION
WORKDIR /pkg
# Copy wholesale: three.module.js re-exports three.core.js and the addons import
# each other, so curating files breaks the module graph with no useful error.
RUN npm pack three@${THREE_VERSION} \
    && tar -xzf three-${THREE_VERSION}.tgz \
    && cp -r package/build /out-build \
    && cp -r package/examples/jsm /out-addons \
    && mkdir -p /out \
    && mv /out-build /out/build \
    && mv /out-addons /out/addons

FROM node:22-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
      chromium fonts-liberation \
    && rm -rf /var/lib/apt/lists/*

COPY --from=three /out /app/three
COPY 3d-shop-api/render/harness.html /app/harness.html
COPY 3d-shop-api/render/render.mjs /app/render.mjs
# The client's own module, so server renders frame a model as the viewer did.
COPY 3d-shop-client/src/components/common/model-displayer/fitToView.js /app/fitToView.js

ARG THREE_VERSION
ENV CHROME_BIN=/usr/bin/chromium \
    THREE_VERSION=${THREE_VERSION}
WORKDIR /app
ENTRYPOINT ["node", "/app/render.mjs"]
