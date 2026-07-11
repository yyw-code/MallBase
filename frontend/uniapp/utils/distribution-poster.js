import QRCode from "qrcode";

export const DISTRIBUTION_POSTER_CANVAS_ID = "distributionPosterCanvas";
export const DISTRIBUTION_POSTER_SIZE = {
  height: 900,
  width: 600,
};

export function drawDistributionPoster(ctx, options = {}) {
  const width = DISTRIBUTION_POSTER_SIZE.width;
  const height = DISTRIBUTION_POSTER_SIZE.height;
  const inviteCode = cleanText(options.inviteCode);
  const qrText = String(options.qrText || options.sharePath || "");
  const coverImage = cleanText(options.coverImage);
  const siteName = cleanText(options.siteName);
  const title = cleanText(options.title) || "分销邀请";
  const subtitle = cleanText(options.subtitle);
  const headerTextWidth = coverImage ? 284 : 432;

  ctx.setFillStyle("#eef4ff");
  ctx.fillRect(0, 0, width, height);

  drawRoundRect(ctx, 30, 30, 540, 840, 34, "#ffffff");
  drawRoundRect(ctx, 48, 48, 504, 238, 30, "#1155dc");
  drawRoundRect(ctx, 72, 246, 112, 8, 4, "#16c784");
  drawCoverImage(ctx, coverImage, 392, 86, 116, 116);

  ctx.setFillStyle("#ffffff");
  ctx.setFontSize(30);
  ctx.setTextAlign("left");
  if (siteName) {
    ctx.fillText(clampText(ctx, siteName, headerTextWidth), 72, 102);
  }
  ctx.setFontSize(52);
  ctx.fillText(clampText(ctx, title, headerTextWidth), 72, 168);

  if (subtitle) {
    ctx.setFillStyle("#dbeafe");
    ctx.setFontSize(22);
    wrapText(ctx, subtitle, 72, 218, headerTextWidth, 30, 2);
  }

  drawRoundRect(ctx, 58, 312, 484, 142, 26, "#f7f9ff");
  ctx.setFillStyle("#667085");
  ctx.setFontSize(22);
  ctx.setTextAlign("left");
  ctx.fillText("我的邀请码", 92, 358);
  ctx.setFillStyle("#1155dc");
  ctx.setFontSize(48);
  ctx.setTextAlign("center");
  ctx.fillText(inviteCode || "-", 300, 420);

  ctx.setFillStyle("#111827");
  ctx.setFontSize(28);
  ctx.setTextAlign("center");
  ctx.fillText("扫码进入商城", 300, 510);
  drawRoundRect(ctx, 150, 540, 300, 300, 28, "#f8fafc");
  drawRoundRect(ctx, 170, 560, 260, 260, 22, "#ffffff");
  drawQrCode(ctx, qrText, 190, 580, 220);

  ctx.setFillStyle("#667085");
  ctx.setFontSize(22);
  ctx.setTextAlign("center");
  ctx.fillText(inviteCode ? "扫码或输入邀请码绑定" : "扫码打开分享页面", 300, 842);
}

function drawCoverImage(ctx, coverImage, x, y, width, height) {
  if (!coverImage) return;

  drawRoundRect(ctx, x - 10, y - 10, width + 20, height + 20, 22, "#ffffff");
  try {
    ctx.drawImage(coverImage, x, y, width, height);
  } catch {
    ctx.setFillStyle("#dbeafe");
    ctx.fillRect(x, y, width, height);
  }
}

function drawQrCode(ctx, text, x, y, size) {
  const modules = createQrModules(text);
  if (!modules) {
    drawQrFallback(ctx, x, y, size);
    return;
  }

  const count = modules.size;
  const cellSize = Math.max(1, Math.floor(size / count));
  const qrSize = cellSize * count;
  const offsetX = x + Math.floor((size - qrSize) / 2);
  const offsetY = y + Math.floor((size - qrSize) / 2);
  const data = modules.data;

  ctx.setFillStyle("#ffffff");
  ctx.fillRect(x, y, size, size);
  ctx.setFillStyle("#111827");
  for (let row = 0; row < count; row += 1) {
    for (let col = 0; col < count; col += 1) {
      if (data[row * count + col]) {
        ctx.fillRect(offsetX + col * cellSize, offsetY + row * cellSize, cellSize, cellSize);
      }
    }
  }
}

function createQrModules(text) {
  const value = String(text || "").trim();
  if (!value) return null;

  try {
    return QRCode.create(value, {
      errorCorrectionLevel: "M",
      margin: 0,
    }).modules;
  } catch {
    return null;
  }
}

function drawQrFallback(ctx, x, y, size) {
  drawRoundRect(ctx, x, y, size, size, 10, "#ffffff");
  ctx.setFillStyle("#6b7280");
  ctx.setFontSize(22);
  ctx.setTextAlign("center");
  ctx.fillText("二维码生成失败", x + size / 2, y + size / 2);
}

function cleanText(value) {
  return String(value || "").trim();
}

function drawRoundRect(ctx, x, y, width, height, radius, color) {
  ctx.beginPath();
  ctx.moveTo(x + radius, y);
  ctx.lineTo(x + width - radius, y);
  ctx.arcTo(x + width, y, x + width, y + radius, radius);
  ctx.lineTo(x + width, y + height - radius);
  ctx.arcTo(x + width, y + height, x + width - radius, y + height, radius);
  ctx.lineTo(x + radius, y + height);
  ctx.arcTo(x, y + height, x, y + height - radius, radius);
  ctx.lineTo(x, y + radius);
  ctx.arcTo(x, y, x + radius, y, radius);
  ctx.closePath();
  ctx.setFillStyle(color);
  ctx.fill();
}

function wrapText(ctx, text, x, y, maxWidth, lineHeight, maxLines) {
  const chars = String(text).split("");
  let line = "";
  let lines = 0;

  for (let i = 0; i < chars.length; i += 1) {
    const nextLine = line + chars[i];
    if (measureTextWidth(ctx, nextLine) > maxWidth && line) {
      lines += 1;
      if (lines >= maxLines) {
        ctx.fillText(`${line.slice(0, Math.max(0, line.length - 2))}...`, x, y);
        return;
      }
      ctx.fillText(line, x, y);
      line = chars[i];
      y += lineHeight;
    } else {
      line = nextLine;
    }
  }

  if (line) {
    ctx.fillText(line, x, y);
  }
}

function clampText(ctx, text, maxWidth) {
  const value = String(text || "");
  if (measureTextWidth(ctx, value) <= maxWidth) {
    return value;
  }

  let result = value;
  while (result.length > 0 && measureTextWidth(ctx, `${result}...`) > maxWidth) {
    result = result.slice(0, -1);
  }
  return result ? `${result}...` : "...";
}

function measureTextWidth(ctx, text) {
  if (typeof ctx.measureText === "function") {
    return ctx.measureText(text).width;
  }
  return String(text).length * 12;
}
