/**
 * eCommerce Chat — Floating Widget
 * Vanilla JS, no framework dependency.
 * Config injected by wp_localize_script as window.eccConfig
 */
(function () {
  "use strict";

  const cfg = window.eccConfig || {};
  if (!cfg.apiUrl) return;

  // ── State ──────────────────────────────────────
  let state = {
    open: false,
    token: null,
    user: null,
    socket: null,
    conversationId: null,
    messages: [],
    typing: false,
    typingTimer: null,
    unread: 0,
  };

  // ── Inject Socket.IO from backend ──────────────
  function loadSocketIO(cb) {
    if (window.io) return cb();
    const s = document.createElement("script");
    s.src = cfg.apiUrl + "/socket.io/socket.io.js";
    s.onload = cb;
    s.onerror = () => console.warn("[ECC] Could not load Socket.IO");
    document.head.appendChild(s);
  }

  // ── Auth sync ──────────────────────────────────
  async function syncAuth() {
    if (!cfg.isLoggedIn) return null;
    const cached = sessionStorage.getItem("ecc_token");
    if (cached) { state.token = cached; return cached; }

    try {
      const res = await fetch(cfg.restUrl + "/auth/sync", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce,
        },
      });
      if (!res.ok) return null;
      const data = await res.json();
      state.token = data.token;
      state.user  = data.user;
      sessionStorage.setItem("ecc_token", data.token);
      return data.token;
    } catch (e) {
      console.warn("[ECC] Auth sync failed:", e);
      return null;
    }
  }

  // ── Connect Socket.IO ──────────────────────────
  function connectSocket(token) {
    if (!window.io || !token) return;
    state.socket = window.io(cfg.apiUrl, {
      auth: { token },
      reconnectionAttempts: 5,
      transports: ["websocket", "polling"],
    });

    state.socket.on("message:new", (msg) => {
      if (msg.conversation !== state.conversationId) return;
      state.messages.push(msg);
      renderMessages();
      if (!state.open) {
        state.unread++;
        updateBadge();
      }
      // Auto mark read if panel open
      if (state.open && state.socket) {
        state.socket.emit("message:read", {
          messageId: msg._id,
          conversationId: state.conversationId,
        });
      }
    });

    state.socket.on("typing:start", ({ userId }) => {
      if (state.user && userId === state.user._id) return;
      state.typing = true;
      renderTyping();
    });

    state.socket.on("typing:stop", ({ userId }) => {
      if (state.user && userId === state.user._id) return;
      state.typing = false;
      renderTyping();
    });
  }

  // ── Start conversation ─────────────────────────
  async function startConversation(type, orderId, productId) {
    if (!state.token) return null;
    try {
      const res = await fetch(cfg.restUrl + "/conversations", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce,
        },
        body: JSON.stringify({
          type:       type       || cfg.defaultType || "support",
          order_id:   orderId   || cfg.orderId   || "",
          product_id: productId || cfg.productId || "",
        }),
      });
      if (!res.ok) return null;
      const data = await res.json();
      return data.conversation || null;
    } catch (e) {
      console.warn("[ECC] Create conversation failed:", e);
      return null;
    }
  }

  // ── Fetch messages ─────────────────────────────
  async function fetchMessages(convId) {
    if (!state.token) return [];
    try {
      const res = await fetch(`${cfg.apiUrl}/api/conversations/${convId}/messages`, {
        headers: { Authorization: "Bearer " + state.token },
      });
      if (!res.ok) return [];
      const data = await res.json();
      return data.messages || [];
    } catch { return []; }
  }

  // ── Send message via socket ────────────────────
  function sendMessage(content) {
    if (!content.trim() || !state.socket || !state.conversationId) return;
    state.socket.emit("message:send", {
      conversationId: state.conversationId,
      content: content.trim(),
      type: "text",
    });
  }

  // ── DOM: Build widget ──────────────────────────
  function buildWidget() {
    const root = document.getElementById("ecc-chat-root");
    if (!root) return;

    root.innerHTML = `
      <!-- Floating button -->
      <button id="ecc-fab" aria-label="Open chat">
        <svg id="ecc-fab-icon" xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor">
          <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
        </svg>
        <span id="ecc-badge" style="display:none;">0</span>
      </button>

      <!-- Chat panel -->
      <div id="ecc-panel" role="dialog" aria-label="Chat panel" style="display:none;">

        <!-- Header -->
        <div id="ecc-header">
          <div id="ecc-header-info">
            <div id="ecc-header-avatar">💬</div>
            <div>
              <div id="ecc-header-title">${escHtml(cfg.widgetTitle || "Chat with us")}</div>
              <div id="ecc-header-status">Connecting…</div>
            </div>
          </div>
          <button id="ecc-close" aria-label="Close chat">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>

        <!-- Body -->
        <div id="ecc-body">
          <div id="ecc-messages"></div>
          <div id="ecc-typing" style="display:none;">
            <span></span><span></span><span></span>
          </div>
          <div id="ecc-scroll-anchor"></div>
        </div>

        <!-- Type selector (shown before conversation starts) -->
        <div id="ecc-type-selector">
          <p>How can we help?</p>
          <div id="ecc-type-buttons">
            <button data-type="support">🎧 Support</button>
            <button data-type="designer">🎨 Designer</button>
            <button data-type="merchant">🛍️ Merchant</button>
          </div>
        </div>

        <!-- Input -->
        <div id="ecc-input-bar" style="display:none;">
          <textarea id="ecc-input" placeholder="Type a message…" rows="1" aria-label="Message input"></textarea>
          <button id="ecc-send" aria-label="Send message">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
          </button>
        </div>

        <!-- Not logged in state -->
        ${!cfg.isLoggedIn ? `
          <div id="ecc-login-gate">
            <p>Please <a href="${escHtml(cfg.loginUrl)}">log in</a> to chat with us.</p>
          </div>` : ""}
      </div>
    `;

    // Apply brand colour
    const color = cfg.widgetColor || "#6366f1";
    document.documentElement.style.setProperty("--ecc-color", color);

    // Bind events
    document.getElementById("ecc-fab").addEventListener("click", togglePanel);
    document.getElementById("ecc-close").addEventListener("click", closePanel);

    document.querySelectorAll("#ecc-type-buttons button").forEach((btn) => {
      btn.addEventListener("click", () => onTypeSelected(btn.dataset.type));
    });

    const input = document.getElementById("ecc-input");
    const send  = document.getElementById("ecc-send");

    if (input) {
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey) {
          e.preventDefault();
          doSend();
        }
      });
      input.addEventListener("input", () => {
        input.style.height = "auto";
        input.style.height = Math.min(input.scrollHeight, 100) + "px";
        emitTyping();
      });
    }

    if (send) send.addEventListener("click", doSend);
  }

  function doSend() {
    const input = document.getElementById("ecc-input");
    if (!input) return;
    const content = input.value.trim();
    if (!content) return;
    sendMessage(content);
    input.value = "";
    input.style.height = "auto";
  }

  // ── Toggle / open / close ──────────────────────
  function togglePanel() { state.open ? closePanel() : openPanel(); }

  async function openPanel() {
    state.open = true;
    state.unread = 0;
    updateBadge();
    document.getElementById("ecc-panel").style.display = "flex";
    document.getElementById("ecc-fab-icon").innerHTML = `
      <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2"/>
      <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2"/>`;

    if (!state.token) {
      setStatus("Authenticating…");
      const token = await syncAuth();
      if (!token) { setStatus("Not logged in"); return; }
      loadSocketIO(() => connectSocket(token));
    }

    setStatus("Online");
    scrollToBottom();
  }

  function closePanel() {
    state.open = false;
    document.getElementById("ecc-panel").style.display = "none";
    document.getElementById("ecc-fab-icon").innerHTML = `
      <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>`;
  }

  // ── Type selected → start conversation ──────────
  async function onTypeSelected(type) {
    document.getElementById("ecc-type-selector").style.display = "none";
    setStatus("Starting conversation…");

    if (!state.token) {
      const token = await syncAuth();
      if (!token) { setStatus("Please log in"); return; }
      loadSocketIO(() => connectSocket(token));
    }

    const convo = await startConversation(type, cfg.orderId, cfg.productId);
    if (!convo) {
      setStatus("Could not start chat. Try again.");
      document.getElementById("ecc-type-selector").style.display = "block";
      return;
    }

    state.conversationId = convo._id;
    state.socket?.emit("join:conversation", convo._id);

    // Load existing messages
    state.messages = await fetchMessages(convo._id);
    renderMessages();

    // Add WooCommerce context note
    if (cfg.orderId) {
      addSystemNote(`📦 Order #${cfg.orderId} context attached.`);
    } else if (cfg.productId) {
      addSystemNote(`🛍️ Asking about: ${cfg.productName || "product #" + cfg.productId}`);
    }

    document.getElementById("ecc-input-bar").style.display = "flex";
    setStatus("Connected");
    scrollToBottom();
  }

  // ── Render ─────────────────────────────────────
  function renderMessages() {
    const container = document.getElementById("ecc-messages");
    if (!container) return;

    container.innerHTML = state.messages.map((msg) => {
      const isOwn = state.user && (msg.sender?._id === state.user._id || msg.sender?.id === state.user._id);
      const time  = new Date(msg.createdAt).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
      const content = msg.isDeleted
        ? '<em style="opacity:.5">Message deleted</em>'
        : escHtml(msg.content);

      return `<div class="ecc-msg ${isOwn ? "ecc-msg--own" : "ecc-msg--other"}">
        <div class="ecc-bubble">${content}</div>
        <div class="ecc-meta">${time}${isOwn ? (msg.readBy?.length > 1 ? " ✓✓" : " ✓") : ""}</div>
      </div>`;
    }).join("");

    scrollToBottom();
  }

  function addSystemNote(text) {
    const container = document.getElementById("ecc-messages");
    if (!container) return;
    const div = document.createElement("div");
    div.className = "ecc-system-note";
    div.textContent = text;
    container.appendChild(div);
    scrollToBottom();
  }

  function renderTyping() {
    const el = document.getElementById("ecc-typing");
    if (el) el.style.display = state.typing ? "flex" : "none";
    if (state.typing) scrollToBottom();
  }

  function setStatus(text) {
    const el = document.getElementById("ecc-header-status");
    if (el) el.textContent = text;
  }

  function updateBadge() {
    const badge = document.getElementById("ecc-badge");
    if (!badge) return;
    badge.style.display = state.unread > 0 ? "flex" : "none";
    badge.textContent   = state.unread > 9 ? "9+" : state.unread;
  }

  function scrollToBottom() {
    const anchor = document.getElementById("ecc-scroll-anchor");
    if (anchor) anchor.scrollIntoView({ behavior: "smooth" });
  }

  // ── Typing emit ────────────────────────────────
  function emitTyping() {
    if (!state.socket || !state.conversationId) return;
    state.socket.emit("typing:start", { conversationId: state.conversationId });
    clearTimeout(state.typingTimer);
    state.typingTimer = setTimeout(() => {
      state.socket?.emit("typing:stop", { conversationId: state.conversationId });
    }, 1500);
  }

  // ── Public API (called by WooCommerce buttons) ──
  window.eccOpenProductChat = function (productId, productName) {
    cfg.productId   = productId;
    cfg.productName = productName;
    openPanel();
    onTypeSelected("merchant");
  };

  window.eccOpenInlineChat = function (widgetId) {
    const el = document.getElementById(widgetId);
    if (!el) return;
    const type      = el.dataset.type      || "support";
    const orderId   = el.dataset.orderId   || "";
    const productId = el.dataset.productId || "";
    if (orderId)   cfg.orderId   = orderId;
    if (productId) cfg.productId = productId;
    openPanel();
    onTypeSelected(type);
  };

  // ── Helpers ─────────────────────────────────────
  function escHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // ── Boot ───────────────────────────────────────
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", buildWidget);
  } else {
    buildWidget();
  }

  // Pre-fetch auth in background
  if (cfg.isLoggedIn) {
    syncAuth().then((token) => {
      if (token) loadSocketIO(() => connectSocket(token));
    });
  }
})();
