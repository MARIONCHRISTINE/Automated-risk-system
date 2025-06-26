import os
import sys
import subprocess
import time

def check_dependencies():
    """Check if required packages are installed"""
    required_packages = ['flask', 'flask_cors']
    missing_packages = []
    
    for package in required_packages:
        try:
            __import__(package)
        except ImportError:
            missing_packages.append(package)
    
    return missing_packages

def main():
    print("🚀 Starting Airtel Risk Management Service...")
    print("=" * 50)
    
    # Check dependencies
    missing = check_dependencies()
    if missing:
        print(f"❌ Missing packages: {', '.join(missing)}")
        print("💡 Run 'python install_dependencies.py' first")
        return
    
    print("✅ All dependencies found")
    
    # Start the service
    print("\n🌟 Starting Flask service on http://localhost:5000")
    print("📝 Press Ctrl+C to stop the service")
    print("-" * 50)
    
    try:
        # Run the risk scoring service
        subprocess.run([sys.executable, "risk_scoring_service.py"])
    except KeyboardInterrupt:
        print("\n\n🛑 Service stopped by user")
    except Exception as e:
        print(f"\n❌ Error starting service: {e}")

if __name__ == "__main__":
    main()
