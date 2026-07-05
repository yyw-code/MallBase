export const DISTRIBUTION_POSTER_CANVAS_ID = "distributionPosterCanvas";
export const DISTRIBUTION_POSTER_SIZE = {
  height: 900,
  width: 600,
};

export function drawDistributionPoster(ctx, options = {}) {
  const width = DISTRIBUTION_POSTER_SIZE.width;
  const height = DISTRIBUTION_POSTER_SIZE.height;
  const inviteCode = String(options.inviteCode || "");
  const sharePath = String(options.sharePath || "");
  const siteName = String(options.siteName || "MallBase");

  ctx.setFillStyle("#f5f7fb");
  ctx.fillRect(0, 0, width, height);

  drawRoundRect(ctx, 42, 42, 516, 816, 28, "#ffffff");
  drawRoundRect(ctx, 42, 42, 516, 190, 28, "#0d50d5");

  ctx.setFillStyle("#ffffff");
  ctx.setFontSize(36);
  ctx.setTextAlign("left");
  ctx.fillText(siteName, 82, 105);
  ctx.setFontSize(54);
  ctx.fillText("分销邀请", 82, 180);

  ctx.setFillStyle("#e8efff");
  ctx.setFontSize(24);
  ctx.fillText("邀请好友下单，成交后按规则计算佣金", 82, 225);

  ctx.setFillStyle("#191b23");
  ctx.setFontSize(26);
  ctx.fillText("我的邀请码", 82, 312);
  drawRoundRect(ctx, 82, 340, 436, 116, 18, "#f3f3fe");
  ctx.setFillStyle("#0d50d5");
  ctx.setFontSize(46);
  ctx.setTextAlign("center");
  ctx.fillText(inviteCode || "-", 300, 413);

  ctx.setFillStyle("#191b23");
  ctx.setFontSize(26);
  ctx.setTextAlign("left");
  ctx.fillText("分享路径", 82, 525);
  drawRoundRect(ctx, 82, 552, 436, 138, 18, "#f8fafc");
  ctx.setFillStyle("#434654");
  ctx.setFontSize(20);
  wrapText(ctx, sharePath || "-", 108, 592, 384, 30, 3);

  drawRoundRect(ctx, 82, 728, 436, 72, 16, "#0d50d5");
  ctx.setFillStyle("#ffffff");
  ctx.setFontSize(24);
  ctx.setTextAlign("center");
  ctx.fillText("复制分享路径或输入邀请码绑定", 300, 773);
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

function measureTextWidth(ctx, text) {
  if (typeof ctx.measureText === "function") {
    return ctx.measureText(text).width;
  }
  return String(text).length * 12;
}
