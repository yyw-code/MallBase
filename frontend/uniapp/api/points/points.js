import { get } from "@/api/request";

export const getPointsInfo = () => get("/client/api/points/info");

export const getPointsLogs = (params) => get("/client/api/points/logs", params);
