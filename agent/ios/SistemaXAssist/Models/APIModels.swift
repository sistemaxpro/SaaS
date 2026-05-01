import Foundation

struct TokenEnvelope<T: Decodable>: Decodable {
    let ok: Bool
    let data: T?
    let error: String?
}

struct AgentRegistrationData: Decodable {
    let device_id: Int
    let agent_token: String
    let expires_at: String
    let platform: String?
}

struct PushRegistrationData: Decodable {
    let registered: Bool
    let fcm_enabled: Bool
    let token_id: Int
    let active_tokens: Int
    let platform: String?
}

struct DeviceStatusData: Decodable {
    let device_id: Int
    let id_empresa: Int
    let id_login: Int
    let device_uuid: String?
    let device_name: String?
    let user_name: String?
    let company_name: String?
    let platform: String?
}
