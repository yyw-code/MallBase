import { get, post, postSilent } from "@/api/request";

export const getDistributionSummary = () => get("/client/api/distribution/summary");

export const getDistributionCommissions = (params) =>
  get("/client/api/distribution/commissions", params);

export const getDistributionLogs = (params) =>
  get("/client/api/distribution/logs", params);

export const getDistributionTeam = (params) =>
  get("/client/api/distribution/team", params);

export const getDistributionWithdraws = (params) =>
  get("/client/api/distribution/withdraws", params);

export const applyDistributionWithdraw = (data) =>
  post("/client/api/distribution/withdraw", data);

export const bindDistributionInvite = (data) =>
  post("/client/api/distribution/bindInvite", data);

export const autoBindDistributionInvite = (data) =>
  postSilent("/client/api/distribution/bindInvite", data);

export const applyDistributionDistributor = (data) =>
  post("/client/api/distribution/apply", data);

export const withdrawDistributionApply = () =>
  post("/client/api/distribution/withdrawApply");

export const getDistributionShareInfo = (params) =>
  get("/client/api/distribution/shareInfo", params);
