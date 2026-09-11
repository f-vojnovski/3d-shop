ARG THREE_VERSION=0.186.0
# By digest, not by tag: `node:22-slim` moves, and a moved base is a different
# rasteriser than the one an old attestation names.
ARG NODE_IMAGE=node:22-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5
# Checked below rather than pinned with apt, because Debian rotates old
# chromium builds out of the archive and an apt pin would eventually fail the
# build with nothing to explain why.
ARG EXPECTED_CHROMIUM=152.0.7977.82

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
       }; }

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
