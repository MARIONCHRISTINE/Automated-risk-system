import requests
import json

def test_risk_service():
    """Test the risk scoring service"""
    base_url = "http://localhost:5000"
    
    print("🧪 Testing Airtel Risk Scoring Service...")
    print("=" * 50)
    
    # Test 1: Health check
    print("\n1. Testing health endpoint...")
    try:
        response = requests.get(f"{base_url}/health")
        if response.status_code == 200:
            print("✅ Health check passed")
            print(f"   Response: {response.json()}")
        else:
            print(f"❌ Health check failed: {response.status_code}")
    except requests.exceptions.ConnectionError:
        print("❌ Cannot connect to service. Make sure it's running on localhost:5000")
        return
    
    # Test 2: Risk calculation
    print("\n2. Testing risk calculation...")
    test_data = {
        "probability": 3,
        "impact": 4
    }
    
    try:
        response = requests.post(
            f"{base_url}/calculate_risk",
            json=test_data,
            headers={'Content-Type': 'application/json'}
        )
        
        if response.status_code == 200:
            result = response.json()
            print("✅ Risk calculation successful")
            print(f"   Input: Probability={test_data['probability']}, Impact={test_data['impact']}")
            print(f"   Output: Score={result['data']['score']}, Level={result['data']['level']}")
        else:
            print(f"❌ Risk calculation failed: {response.status_code}")
            print(f"   Response: {response.text}")
    except Exception as e:
        print(f"❌ Error testing risk calculation: {e}")
    
    # Test 3: Risk matrix
    print("\n3. Testing risk matrix...")
    try:
        response = requests.get(f"{base_url}/risk_matrix")
        if response.status_code == 200:
            result = response.json()
            print("✅ Risk matrix generation successful")
            print(f"   Matrix size: {len(result['matrix'])}x{len(result['matrix'][0])}")
        else:
            print(f"❌ Risk matrix failed: {response.status_code}")
    except Exception as e:
        print(f"❌ Error testing risk matrix: {e}")
    
    print("\n" + "=" * 50)
    print("🎯 Testing completed!")

if __name__ == "__main__":
    test_risk_service()
