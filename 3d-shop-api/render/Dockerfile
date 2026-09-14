ARG THREE_VERSION=0.186.0
# By digest, not by tag: `node:22-slim` moves, and a moved base is a different
# rasteriser than the one an old attestation names.
ARG NODE_IMAGE=node:22-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5
# Checked below rather than pinned with apt, because Debian rotates old
# chromium builds out of the archive and an apt pin would eventually fail the
# build with nothing to explain why.
ARG EXPECTED_CHROMIUM=152.0.7977.82
# An .fbx reaches the renderer only through assimp, so the converter is part of
# what drew the pixels and is pinned the same way.
ARG EXPECTED_ASSIMP=5.2

FROM ${NODE_IMAGE} AS three
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

FROM ${NODE_IMAGE}
ARG EXPECTED_CHROMIUM
ARG EXPECTED_ASSIMP
# assimp converts the formats PHP cannot parse into glb.
RUN apt-get update && apt-get install -y --no-install-recommends \
      chromium fonts-liberation assimp-utils \
    && rm -rf /var/lib/apt/lists/* \
    && chromium --version \
    && { chromium --version | grep -qF "${EXPECTED_CHROMIUM}" || { \
         echo "Chromium is not ${EXPECTED_CHROMIUM}. Stills attested by an" \
              "earlier image cannot be reproduced byte-for-byte by this one;" \
              "bump EXPECTED_CHROMIUM once you accept that."; \
         exit 1; \
       }; } \
    && assimp version \
    && { assimp version | grep -qE "Version[[:space:]]+${EXPECTED_ASSIMP}" || { \
         echo "assimp is not ${EXPECTED_ASSIMP}. Every .fbx listing was drawn" \
              "from a glb this converter produced, and another version makes" \
              "different bytes; bump EXPECTED_ASSIMP once you accept that."; \
         exit 1; \
       }; }

COPY --from=three /out /app/three
COPY 3d-shop-api/render/harness.html /app/harness.html
COPY 3d-shop-api/render/render.mjs /app/render.mjs
# Simplifying runs on its own entrypoint: the render path is what an attested
# still is reproduced from, and nothing here should be able to disturb it.
COPY 3d-shop-api/render/proxy.html /app/proxy.html
COPY 3d-shop-api/render/proxy.mjs /app/proxy.mjs
# Moving pictures likewise: a clip is drawn beside the still path, never through it.
COPY 3d-shop-api/render/animate.html /app/animate.html
COPY 3d-shop-api/render/animate.mjs /app/animate.mjs
# The client's own module, so server renders frame a model as the viewer did.
COPY 3d-shop-client/src/components/common/model-displayer/fitToView.js /app/fitToView.js
COPY 3d-shop-client/src/components/common/model-displayer/simplify.js /app/simplify.js
COPY 3d-shop-client/src/components/common/model-displayer/shrinkTextures.js /app/shrinkTextures.js

# The root filesystem is read-only, and Chromium treats a home it cannot write
# as fatal: the crash handler it starts first gets no database directory, exits,
# and the browser dies on the reset socket before drawing anything. /tmp is the
# one writable mount.
ENV HOME=/tmp

ARG THREE_VERSION
ENV CHROME_BIN=/usr/bin/chromium \
    THREE_VERSION=${THREE_VERSION}
WORKDIR /app
ENTRYPOINT ["node", "/app/render.mjs"]
